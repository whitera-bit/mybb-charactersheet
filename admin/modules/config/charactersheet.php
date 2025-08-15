<?php
if (!defined("IN_MYBB") || !defined("IN_ADMINCP")) {
    die("Zugriff verweigert.");
}

if ($mybb->input['action'] === 'export_fields') {
    global $db;

    // WICHTIG: Vorherigen Output verwerfen (falls doch was gepuffert wurde)
    while (ob_get_level() > 0) { @ob_end_clean(); }

    // Felder laden
    $q = $db->simple_select('charactersheet_fields', '*', '', ['order_by' => 'disporder']);
    $rows = [];
    while ($r = $db->fetch_array($q)) { $rows[] = $r; }

    // Kleine Hilfsfunktion für CDATA (für HTML in Beschreibungen/Optionen)
    $toCdata = function(string $s): string {
        // verhindert "]]>" im Inhalt
        $s = str_replace(']]>', ']]]]><![CDATA[>', $s);
        return "<![CDATA[".$s."]]>";
    };

    // XML bauen
    $xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    $xml .= "<charactersheet_fields exported=\"".gmdate('c')."\">\n";
    foreach ($rows as $r) {
        $xml .= "  <field>\n";
        foreach ($r as $col => $val) {
            if (is_int($col)) continue;

            // Spalten mit möglichem HTML in CDATA, Rest normal escapen
            if (in_array($col, ['title', 'description', 'options', 'viewablegroups', 'editablegroups', 'unit_label', 'var_name', 'type', 'field_scope', 'required', /*'hide_fully'*/], true)) {
                $xml .= "    <{$col}>".$toCdata((string)$val)."</{$col}>\n";
            } else {
                $xml .= "    <{$col}>".htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</{$col}>\n";
            }
        }
        $xml .= "  </field>\n";
    }
    $xml .= "</charactersheet_fields>\n";

    // Download-Header
    $fname = 'charactersheet_fields_'.date('Ymd_His').'.xml';
    header('Content-Type: application/xml; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="'.$fname.'"');
    header('Content-Length: '.strlen($xml));

    echo $xml;
    exit;
}

// Sprachdatei laden (optional)
// $lang->load("charactersheet");

// Seitentitel im ACP
 $page->add_breadcrumb_item("Charakterbogen Verwalten", "index.php?module=config-charactersheet");

// Seite starten
 $page->output_header("Charakterbogen verwalten");

// Völlig unnötige schönere Darstellung für die Optionen in der ACP-Übersicht
function cs_format_spectrum_options($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    $arr = json_decode($raw, true);
    if (!is_array($arr)) {
        // Fallback: Rohwert sichern ausgeben
        return htmlspecialchars_uni($raw);
    }

    $steps = isset($arr['steps']) ? (int)$arr['steps'] : null;
    $left  = isset($arr['leftlabel']) ? (string)$arr['leftlabel'] : '';
    $right = isset($arr['rightlabel']) ? (string)$arr['rightlabel'] : '';

    $parts = [];
    if ($steps && $steps > 0) {
        $parts[] = $steps . ' Stufen';
    }
    if ($left !== '' || $right !== '') {
        $parts[] = htmlspecialchars_uni($left) . ' ↔ ' . htmlspecialchars_uni($right);
    }
    return implode(' · ', $parts);
}

// Robust: JSON oder INI → Array
function cs_parse_kv_options($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return [];
    $arr = json_decode($raw, true);
    if (is_array($arr)) return $arr;
    $ini = @parse_ini_string($raw);
    return is_array($ini) ? $ini : [];
}

// Max/Min-Darstellung (gemeinsam für URL & Upload)
function cs_format_image_common(array $opts) {
    $mw = isset($opts['maxwidth'])  ? (int)$opts['maxwidth']  : 0;
    $mh = isset($opts['maxheight']) ? (int)$opts['maxheight'] : 0;
    $nw = isset($opts['minwidth'])  ? (int)$opts['minwidth']  : 0;
    $nh = isset($opts['minheight']) ? (int)$opts['minheight'] : 0;

    $maxPart = ($mw > 0 || $mh > 0) ? "Max: {$mw}×{$mh}" : "Max: frei";
    $minPart = ($nw > 0 || $nh > 0) ? "Min: {$nw}×{$nh}" : "Min: —";
    return $maxPart . ' · ' . $minPart;
}

// "2M", "2048k", "3GB", "2", etc. → hübsch ("2 MB", "2 GB", …)
// Konvention: reine Zahl = MB (praktisch im ACP)
function cs_filesize_to_str($val) {
    $s = trim((string)$val);
    if ($s === '' || $s === '0') return 'frei';

    // reine Zahl → MB
    if (ctype_digit($s)) {
        return (int)$s . ' MB';
    }

    // Einheiten
    $u = strtoupper(preg_replace('/[^A-Za-z]/', '', $s));
    $n = (float)preg_replace('/[^0-9.]/', '', $s);
    if ($n <= 0) return 'frei';

    switch ($u) {
        case 'G': case 'GB': return rtrim(rtrim(number_format($n, 2, ',', ''), '0'), ',') . ' GB';
        case 'M': case 'MB': return rtrim(rtrim(number_format($n, 2, ',', ''), '0'), ',') . ' MB';
        case 'K': case 'KB': return rtrim(rtrim(number_format($n, 0, ',', ''), '0'), ',') . ' KB';
        default:             return $s; // unbekannte Einheit: roh anzeigen
    }
}

// Bild: nur URL (gleiche Felder wie Upload außer Size/Formats)
function cs_format_image_url_options($raw) {
    $opts = cs_parse_kv_options($raw);
    return cs_format_image_common($opts);
}

// Bild: Upload (zusätzlich Formate + Größenlimit)
function cs_format_image_upload_options($raw) {
    $opts = cs_parse_kv_options($raw);

    $parts = [];
    $parts[] = cs_format_image_common($opts);

    // Formate (array oder kommagetrennt)
    $formatsRaw = $opts['formats'] ?? $opts['format'] ?? '';
    $formats = [];
    if (is_array($formatsRaw)) {
        $formats = $formatsRaw;
    } elseif (is_string($formatsRaw)) {
        $formats = array_filter(array_map('trim', explode(',', $formatsRaw)));
    }
    if ($formats) {
        $formats = array_map(function($f){ return htmlspecialchars_uni(strtoupper($f)); }, $formats);
        $parts[] = 'Formate: ' . implode(', ', $formats);
    } else {
        $parts[] = 'Formate: frei';
    }

    // Größenlimit
    $sizeRaw = $opts['size_limit'] ?? $opts['maxsize'] ?? '';
    $parts[] = 'Größe: ' . cs_filesize_to_str($sizeRaw);

    return implode(' · ', $parts);
}

// Mehrfachoptionen hübsch: "A · B · C"
function cs_format_multi_options($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return '';

    // 1) JSON?
    $data = json_decode($raw, true);
    $items = [];
    if (is_array($data)) {
        // JSON-Liste: ["A","B","C"]
        if (array_keys($data) === range(0, count($data)-1)) {
            foreach ($data as $v) { $items[] = (string)$v; }
        } else {
            // JSON-Objekt: evtl. {"options":[...]} oder {"1":"A","2":"B"}
            if (isset($data['options'])) {
                if (is_array($data['options'])) {
                    foreach ($data['options'] as $v) { $items[] = (string)$v; }
                } elseif (is_string($data['options'])) {
                    $raw = $data['options']; // fällt unten in die String-Parser
                }
            } else {
                foreach ($data as $k => $v) { $items[] = (string)$v; }
            }
        }
    }

    if (!$items) {
        // 2) INI?
        $ini = @parse_ini_string($raw, false, INI_SCANNER_RAW);
        if (is_array($ini) && $ini) {
            foreach ($ini as $k => $v) { $items[] = (string)$v; }
        }
    }

    if (!$items) {
        // 3) Plain-String: erst Zeilen, sonst Komma/Pipe
        $lines = preg_split("/[\r\n]+/", $raw);
        if (count($lines) <= 1) {
            $lines = preg_split('/[,|;]+/', $raw);
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            // "key=Label" → Label verwenden
            if (strpos($line, '=') !== false) {
                list($k, $v) = array_map('trim', explode('=', $line, 2));
                $items[] = $v;
            } else {
                $items[] = $line;
            }
        }
    }

    // säubern + escapen
    $items = array_filter(array_map('trim', $items), function($s){ return $s !== ''; });
    $items = array_map('htmlspecialchars_uni', $items);

    return implode(' · ', $items);
}

// Percent-Optionen hübsch: "Maximalwert: x"
function cs_format_percent_options($raw) {
    $optstr = trim((string)$raw);
    if ($optstr !== '' && ctype_digit($optstr)) {
        $max = max(1, (int)$optstr);
    } else {
        $opts = @parse_ini_string($optstr);
        $max = max(1, (int)($opts['max'] ?? 100));
    }
    return 'Maximalwert: ' . $max;
}

if ($mybb->input['action'] === 'import_fields') {
    global $page, $db, $mybb;

    // Upload-Form
    if ($mybb->request_method !== 'post') {
        echo '<form action="index.php?module=config-charactersheet&action=import_fields" method="post" enctype="multipart/form-data">';
        echo '<input type="hidden" name="my_post_key" value="'.$mybb->post_code.'">';
        echo '<p><input type="file" name="fieldsxml" accept=".xml" required></p>';
        echo '<p><label><input type="checkbox" name="wipe" value="1" checked> Bestehende Felder vorher löschen (Empfohlen)</label></p>';
        echo '<p><button class="button" type="submit">Import starten</button></p>';
        echo '</form>';
        $page->output_footer();
        exit;
    }

    // Sicherheits-Check
    verify_post_check($mybb->get_input('my_post_key'));

    if (empty($_FILES['fieldsxml']['tmp_name']) || !is_uploaded_file($_FILES['fieldsxml']['tmp_name'])) {
        flash_message('Keine XML-Datei hochgeladen.', 'error');
        admin_redirect('index.php?module=config-charactersheet&action=import_fields');
    }

    $xmlstr = @file_get_contents($_FILES['fieldsxml']['tmp_name']);
    $sx = @simplexml_load_string($xmlstr);
    if (!$sx || !isset($sx->field)) {
        flash_message('Ungültiges XML-Format.', 'error');
        admin_redirect('index.php?module=config-charactersheet&action=import_fields');
    }

    // Optional: alles leeren
    if ((int)$mybb->get_input('wipe') === 1) {
        $db->delete_query('charactersheet_fields', '1=1');
    }

    // Einfügen
    $count = 0;
    foreach ($sx->field as $f) {
        // Felder aus XML lesen – passe Spalten an dein Schema an
        $insert = [
            'var_name'       => $db->escape_string((string)$f->var_name),
            'title'          => $db->escape_string((string)$f->title),
            'type'           => $db->escape_string((string)$f->type),
            'options'        => $db->escape_string((string)$f->options),
            'unit_label'     => $db->escape_string((string)$f->unit_label),
            'viewablegroups' => $db->escape_string((string)$f->viewablegroups),
            'editablegroups' => $db->escape_string((string)$f->editablegroups),
            'field_scope'    => $db->escape_string((string)$f->field_scope),
            'required'       => (int)$f->required,
            'disporder'      => (int)$f->disporder,
            //'hide_fully'     => (int)$f->hide_fully,
            'description'    => $db->escape_string((string)$f->description),
        ];
        // Achtung: 'fid' auto_increment NICHT setzen
        $db->insert_query('charactersheet_fields', $insert);
        $count++;
    }

    flash_message("Import abgeschlossen: {$count} Felder übernommen.", 'success');
    admin_redirect('index.php?module=config-charactersheet'); // zurück zum Dashboard
}



if ($mybb->input['action'] == 'docs') {
    global $page, $db, $mybb;

    // (nur falls Table-Klasse noch nicht geladen ist)
    if(!class_exists('Table')) {
        require_once MYBB_ROOT.'inc/class_table.php';
    }

    // Header + Breadcrumb + Tabs
    $page->add_breadcrumb_item('Template-Variablen', 'index.php?module=config-charactersheet&action=docs');

    // Tabs erneut ausgeben, damit der aktive Tab markiert ist
    if(isset($sub_tabs) && is_array($sub_tabs)) {
        $page->output_nav_tabs($sub_tabs, 'charactersheet_docs');
    }


    // 2.1 Feste (System-)Variablen – passe nach Bedarf an
    $fixed = [
        ['{$edit_link}',                 'Edit‑Link für den eigenen Steckbrief (nur sichtbar beim Eigentümer)'],

        // Falls du die getrennten Bild-Variablen nutzt:
        ['{$cs_image_url_src}',          'Bildquelle (URL‑Feld)'],
        ['{$cs_image_url_alt}',          'Alt‑Text (URL‑Feld)'],
        ['{$cs_image_url_min_w}',        'min‑Breite (URL‑Feld, px)'],
        ['{$cs_image_url_min_h}',        'min‑Höhe (URL‑Feld, px)'],
        ['{$cs_image_url_max_w}',        'max‑Breite (URL‑Feld, px)'],
        ['{$cs_image_url_max_h}',        'max‑Höhe (URL‑Feld, px)'],

        ['{$cs_image_upload_src}',       'Bildquelle (Upload‑Feld)'],
        ['{$cs_image_upload_alt}',       'Alt‑Text (Upload‑Feld)'],
        ['{$cs_image_upload_min_w}',     'min‑Breite (Upload‑Feld, px)'],
        ['{$cs_image_upload_min_h}',     'min‑Höhe (Upload‑Feld, px)'],
        ['{$cs_image_upload_max_w}',     'max‑Breite (Upload‑Feld, px)'],
        ['{$cs_image_upload_max_h}',     'max‑Höhe (Upload‑Feld, px)'],

        // Spektrum (View)
        ['{$left}',                      'Label links (Spektrum)'],
        ['{$right}',                     'Label rechts (Spektrum)'],
        ['{$dots}',                      'Dot‑HTML (Spektrum)'],

        // Prozent (View)
        ['{$cs_val}',                    'Wert (Prozentfeld)'],
        ['{$cs_max}',                    'Maximalwert (Prozentfeld)'],
        ['{$cs_percent}',                'Prozentsatz (0–100, gerundet)'],
    ];

    // 2.2 Templates – Übersicht mit Kurzbeschreibung
    $templates_list = [
        // View-Templates (Standardanzeige)
        ['charactersheet_view_default',      'Standard-Ansicht des Steckbriefs mit automatischer Feldreihenfolge'],
        ['charactersheet_view_custom',       'Custom-Ansicht, komplett frei gestaltbar mit eigenen Variablen'],

        // Eingabe-Templates
        ['charactersheet_edit',              'Standard-Eingabeseite für alle Felder'],
        ['charactersheet_edit_text',         'Eingabefeld für normale Textfelder'],
        ['charactersheet_edit_number',       'Eingabefeld für Zahlenfelder'],
        ['charactersheet_edit_percent',      'Eingabefeld für Prozentfelder (Slider oder Input)'],
        ['charactersheet_edit_spectrum',     'Eingabefeld für Spektrum (Dots mit Labels)'],
        ['charactersheet_edit_checkbox',     'Mehrfachauswahl-Checkboxen'],
        ['charactersheet_edit_image_url',    'Eingabefeld für Bild-URL'],
        ['charactersheet_edit_image_upload', 'Eingabefeld für Bild-Upload'],

        // Anzeige-Templates für Feldtypen
        ['charactersheet_view_percent',      'Anzeige eines Prozentfeldes (Balken, Werte, Prozent)'],
        ['charactersheet_view_spectrum',     'Anzeige eines Spektrumfeldes (Dots mit aktiver Markierung)'],
        ['charactersheet_view_checkbox',     'Anzeige der gewählten Checkbox-Werte als Liste'],
        ['charactersheet_view_image_url',    'Anzeige eines Bildes aus einer URL'],
        ['charactersheet_view_image_upload', 'Anzeige eines hochgeladenen Bildes'],
        ['charactersheet_view_rankscale',    'Anzeige eines Rank-Scale-Feldes'],

        // Sonstiges
        ['charactersheet_edit-link',         'Edit-Link, der nur beim Eigentümer des Steckbriefs sichtbar ist'],
    ];


    // 2.2 Dynamische Feld-Variablen aus DB (für Custom‑Templates)
    // Hinweis: Diese entsprechen {$charactersheet_view_<var_name>}
    $dynamic = [];
    $q = $db->simple_select('charactersheet_fields', 'var_name, title, type, field_scope', '', ['order_by' => 'disporder']);
    while($f = $db->fetch_array($q)) {
        $dynamic[] = [
            '{$charactersheet_view_'.$f['var_name'].'}',
            htmlspecialchars_uni(($f['title'] ?: $f['var_name']).' · Typ: '.$f['type'].($f['field_scope']=='layout' ? ' (Layout)' : ''))
        ];
    }

    // 2.3 Tabellen ausgeben
    $table = new Table;
    $table->construct_cell("<div class=\"description\" style=\"margin: 10px; font-size: 14px;\">
            <p><h2><strong>So nutzt du das Plugin:</strong></h2></p>
            Dieses Plugin ist ein eigenständiges Charactersheet System. Du kannst hier im ACP Felder verschiedener Typen anlegen, die dann im Forum von den Usern über einen Edit-Befehl ausgefüllt werden. In einer
            separaten Ansicht werden dann die Informationen als Steckbrief ausgegeben. Jeder User kann nur seinen eigenen Steckbrief bearbeiten und du kannst unter <code>Einstellungen > Charactersheet EX Einstellungen</code>
            einstellen, welche Gruppen den Steckbrief grundsätzlich bearbeiten oder sehen können.<br>
            In diesem Bereich hier kannst du den Steckbrief anlegen, indem du Feld für Feld erstellst. Jedes Feld kann separat mit Gruppenrechten versehen werden, sodass du auch Felder erstellen kannst, die zum
            Beispiel nur User und Admins sehen (für Regelbestätigungen, etc.). Es gibt eine ganze Reihe von Spezialfeldern, aus denen du wählen kannst, unter anderem Textfelder, Zahlenfelder, Mehrfachauswahl, Einfachauswahl,
            Bild-Upload und Bild-URLs, Progressbars und Ränge. Sobald du dent Feldtyp auswählst, erscheinen Zusatzoptionen, um das Feld weiter zu definieren.<br>
            Außerdem gibt es sogenannte Layout-Felder. Das sind Felder, die im Edit und/oder der Steckbriefansicht angezeigt werden, aber nicht bearbeitet werden können. So kannst du beispielsweise Überschriften und
            zusätzliche Informationstexte einfügen.<br><br>
            Du kannst Felder bearbeiten, duplizieren und löschen. Wenn du mehrere Felder bequem anders anordnen willst, nutze den Button <code>Reihenfolge der Felder verändern</code>.
            <br><br>
            <b>Kurzanleitung</b>
            <ol style=\"margin-left:1.2em\">
                <li><strong>Ansicht festlegen:</strong> ACP → <em>Konfiguration → Einstellungen → Charactersheet EX Einstellungen</em>. Wähle \"Ja\" bei <code>Default View verwenden</code> für die Standardansicht und
                \"Nein\" für die Custom Ansicht (siehe unten). </li>
                <li><strong>Felder anlegen:</strong> Lege unter <em>Konfiguration → Charakterbogen-Felder</em> beliebig viele Felder an.</li>
                <li><strong>Templates/CSS:</strong> Passe Feld- und View-Templates und den Style in <code>charactersheet.css</code> an.</li>
                <li><strong>Links:</strong> Rufe die Charakterbogen-Ansichten im Forum auf unter:
                <ul><li><b>Steckbriefansicht:</b> charactersheet.php?action=view&uid=X (X = ID des Users)</li>
                <li><b>Bearbeitungsansicht: charactersheet.php?action=edit</b></li></ul>
                <li><strong>Spezialfelder:</strong> Für Bild/Prozent/Spektrum stehen Zusatz‑Variablen bereit (siehe Tabellen unten).</li>
            </ol>
            <p>Hinweis: Diese Anpassungen betreffen die <em>Ansicht</em>. Die <em>Eingabe</em> steuerst du über die jeweiligen <code>charactersheet_edit_*</code>-Templates.</p>

            <p><strong>So nutzt du Custom‑Templates:</strong></p>
            Dieses Plugin bietet die Möglichkeit, eine Default Ansicht des Steckbriefes zu nutzen, bei der alle Felder automatisch nach der Reihenfolge im ACP angeordnet werden. 
            Es ist möglich, die Feldtypen in den jeweiligen Templates anzupassen. Außerdem kannst du die charactersheet.css ändern und so die CSS classes individuell gestalten.
            Aber die Änderungen gelten dann für jedes Feld dieses Typs. Wenn du vorhast, den Steckbrief komplett frei zu gestalten, 
            befolge folgende Schritte:
            <ol style=\"margin-left:1.2em\">
                <li>Wähle unter <code><b>Einstellungen > Charactersheet EX Einstellungen</b> Nein</code> bei <code><b>Default View verwenden</b></code>. </li>
                <li>Öffne die Custom View‑Template <code>charactersheet_view_custom</code>. Die Template enthält die übliche MyBB-Seitenstruktur und einen Platzhalter.</li>
                <li>Ersetze den Platzhalter mit den entsprechenden Variablen der Felder, z.B. <code>{$charactersheet_view_name}</code>. Beachte, dass die Variablen bei dieser Methode nicht
                automatisch eingefügt werden. Wenn du die Variable nicht von Hand in die Template einfügst, wird sie nicht angezeigt, auch wenn die Gruppenrechte es vorsehen.
                Umgekehrt wird eine Variable auch dann nicht angezeigt, wenn du sie eingebaut hast, wenn die Gruppenrechte es für die spezifische Gruppe verbieten. Bei Bild-Feldern hast du
                die Möglichkeit, ein Platzhalterbild einzufügen. Du kannst die Variablen in der Template komplett frei einbauen. Unten findest du die Liste mit den festen und Systemvariablen
                sowie den Variablen der Felder, die du selbst angelegt hast. Die Feld-Variablen, die du selbst angelegt hast, geben immer nur den Inhalt des Feldes wider.</li>
                <li>Für Spezialfelder (Bild, Prozent, Spektrum) stehen zusätzliche Variablen zur Verfügung (siehe feste Variablen).</li>
            </ol>
            Beachte, dass diese Anpassungen nur für die Charactersheet Ansicht gilt, nicht für die Eingabe. Du kannst die Eingabefelder über die Templates <code>charactersheet_edit_x</code> gestalten, aber
            sie werden der Reihenfolge und den Gruppenrechten nach für jeden User gleich angezeigt.
            <br><br>
            <p><strong>Import & Export Funktion</strong></p>
            Das Plugin kommt mit einer Import/Export-Funktion, die du neben den anderen Buttons in der <b>Charakterbogenverwaltung</b> findest. Mit einem Klick auf den Export-Button lädst du eine XML-Datei herunter,
            die du als Backup deiner Steckbrief-Struktur, also der Felder, nutzen kannst. 
             <br><b>Achtung!</b> Das exportiert nicht die Steckbriefe der User, also das, was User eingegeben haben. Diese Informationen werden zusammen mit den Posts, Foren, Usern usw.
            in der Datenbank gespeichert und können über das phpmyAdmin aufgerufen werden. Die Import/Export-Funktion ist dazu da, eine bereits erstellte Steckbriefstruktur, also die Felder und Layout-Elemente,
            schnell wieder herzustellen oder zu kopieren.
    </div>", ['colspan' => 1]);
    $table->construct_row();
    $table->output("Charaktersheet View selbst gestalten");

    // Tabelle 2: Feste Variablen
    $table = new Table;
    $table->construct_header('Variable', ['width' => '40%']);
    $table->construct_header('Beschreibung');
    foreach($fixed as $row){
        $table->construct_cell('<code>'.$row[0].'</code>');
        $table->construct_cell($row[1]);
        $table->construct_row();
    }
    $table->output('Feste/System‑Variablen');

    // Tabelle 3: Dynamische Feld‑Variablen
    $table2 = new Table;
    $table2->construct_header('Variable', ['width' => '40%']);
    $table2->construct_header('Beschreibung');
    foreach($dynamic as $row){
        $table2->construct_cell('<code>'.$row[0].'</code>');
        $table2->construct_cell($row[1]);
        $table2->construct_row();
    }
    $table2->output('Dynamische Feld-Variablen (nach den von dir erstellen Charactersheetfeldern');

    // Tabelle 4: Templates
    $table = new Table;
    $table->construct_header('Template-Name', ['width' => '40%']);
    $table->construct_header('Beschreibung', ['width' => '60%']);

    foreach ($templates_list as $tpl) {
        $table->construct_cell('<code>'.$tpl[0].'</code>');
        $table->construct_cell($tpl[1]);
        $table->construct_row();
    }

    echo $table->output("Templates – Übersicht");


    // Footer & Ende
    $page->output_footer();
    exit;
}


// Eintrag duplizieren

if ($mybb->input['action'] == "duplicate") {
    $fid = (int)$mybb->input['fid'];
    $field = $db->fetch_array($db->simple_select("charactersheet_fields", "*", "fid='{$fid}'"));
    
    if (!$field) {
        flash_message("Feld nicht gefunden.", "error");
        admin_redirect("index.php?module=config-charactersheet");
    }

    // Eingaben für Formular vorbereiten
    $mybb->input = array_merge($mybb->input, [
        'title' => $field['title'],
        'description' => $field['description'],
        'type' => $field['type'],
        'options' => $field['options'],
        'viewablegroups' => explode(',', $field['viewablegroups']),
        'editablegroups' => explode(',', $field['editablegroups']),
        'disporder' => $field['disporder'],
    ]);

    // Spezialfall: JSON-Optionen (für Bilder)
    if (in_array($field['type'], ['image_url', 'image_upload'])) {
        $json = json_decode($field['options'], true);
        if (is_array($json)) {
            foreach ($json as $key => $val) {
                $mybb->input["option_{$key}"] = $val;
            }
        }
    }

    // Springe in den Add-Block, aber mit vorausgefülltem Formular
    $mybb->input['action'] = "add";
    $mybb->input['from_duplicate'] = $field['title'];
}

// Reihenfolge ändern

if ($mybb->input['action'] == "reorder") {
    $page->add_breadcrumb_item("Feldreihenfolge ändern");

    // Felder speichern
    if ($mybb->request_method == "post" && !empty($mybb->input['orderdata'])) {
        $ids = explode(",", $mybb->input['orderdata']);

        $disporder = 1;
        foreach ($ids as $fid) {
            $fid = (int)$fid;
            $db->update_query("charactersheet_fields", ['disporder' => $disporder], "fid = {$fid}");
            $disporder++;
        }

        flash_message("Reihenfolge gespeichert.", "success");
        admin_redirect("index.php?module=config-charactersheet");
    }



    // Felder laden
    $fields = [];
    $query = $db->simple_select("charactersheet_fields", "*", "", ["order_by" => "disporder"]);
    while ($field = $db->fetch_array($query)) {
        $fields[] = $field;
    }

    // HTML für Sortierliste vorbereiten
    echo '<form method="post" action="index.php?module=config-charactersheet&action=reorder">';
    echo '<input type="hidden" name="my_post_key" value="' . $mybb->post_code . '" />';
    echo '<p>Ziehe die Felder in die gewünschte Reihenfolge und klicke auf "Speichern".</p>';
    echo '<ul id="reorder-list" class="cs-reorder-list">';

    foreach ($fields as $field) {
        $label = htmlspecialchars_uni($field['title']);
        echo "<li class='reorder-item' data-fid='{$field['fid']}'>{$label}</li>";
    }

    echo '</ul>';
    echo '<input type="hidden" name="orderdata" id="orderdata" value="" />';
    echo '<br><input type="submit" name="submit" value="Reihenfolge speichern" class="button" />';
    echo '</form>';

    // jQuery UI für Drag & Drop
    echo <<<JS
        <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
        <script>
        $(function() {
            $("#reorder-list").sortable({
                update: function() {
                    let order = [];
                    $(".reorder-item").each(function() {
                        order.push($(this).data("fid"));
                    });
                    $("#orderdata").val(order.join(","));
                }
            });
        });
        </script>
    JS;

    echo '<style>
    .cs-reorder-list { list-style: none; padding: 0; }
    .cs-reorder-list li {
        background: #f5f5f5;
        border: 1px solid #ccc;
        padding: 8px;
        margin-bottom: 4px;
        cursor: move;
    }
    </style>';

    $page->output_footer();
    exit;
}


// Eintrag hinzufügen

 if ($mybb->input['action'] == "add") {
    $page->add_breadcrumb_item("Neues Feld hinzufügen");
    if ($mybb->input['from_duplicate']) {
        $fieldtitle = htmlspecialchars_uni($mybb->input['from_duplicate']);
        $page->output_inline_error([
            "Du bearbeitest eine Kopie des Feldes <strong>{$fieldtitle}</strong>. Bitte gib einen neuen Variablennamen ein."
        ]);
    }

    if (!isset($mybb->input['disporder']) || $mybb->input['disporder'] === '') {
    $max_disporder = (int)$db->fetch_field(
        $db->simple_select("charactersheet_fields", "MAX(disporder) AS max_disporder"),
        "max_disporder"
    );
    $mybb->input['disporder'] = $max_disporder + 1;
    }




    // Wenn abgesendet wurde
    if ($mybb->request_method == "post") {

        $viewablegroups = implode(',', array_map('intval', (array)$mybb->input['viewablegroups']));
        $editablegroups = implode(',', array_map('intval', (array)$mybb->input['editablegroups']));

        if ($mybb->input['type'] == 'image_url') {
            $options = json_encode([
                'maxwidth' => (int)$mybb->input['option_maxwidth'],
                'maxheight' => (int)$mybb->input['option_maxheight'],
                'minwidth' => (int)$mybb->input['option_minwidth'],
                'minheight' => (int)$mybb->input['option_minheight'],
                'placeholder'  => trim((string)$mybb->input['option_placeholder']),

            ]);
        } else {
            $options = str_replace("\r", '', $mybb->input['options']);
        }


        $new_field = [
            'var_name' => $db->escape_string($mybb->input['var_name']),
            'title' => $db->escape_string($mybb->input['title']),
            'description' => $db->escape_string($mybb->get_input('description', MyBB::INPUT_STRING)),
            'type' => $db->escape_string($mybb->input['type']),
            'unit_label' => $db->escape_string($mybb->input['unit_label']),
            'viewablegroups' => $db->escape_string(implode(',', (array)$mybb->input['viewablegroups'])),
            'editablegroups' => $db->escape_string(implode(',', (array)$mybb->input['editablegroups'])),
            'options' => $db->escape_string($options),
            'disporder' => (int)$mybb->input['disporder'],
            // 'hide_fully' => (int)$mybb->input['hide_fully'],
            'required' => (int)$mybb->input['required']
        ];

        $db->insert_query("charactersheet_fields", $new_field);
        flash_message("Feld erfolgreich angelegt.", "success");
        admin_redirect("index.php?module=config-charactersheet");
    }

    $form = new Form("index.php?module=config-charactersheet&amp;action=add", "post");
    $form_container = new FormContainer("Neues Feld anlegen", [], 'general_form_container');

    $form_container->output_row("Interner Feldname", "Nur Kleinbuchstaben, Zahlen, Unterstriche.", $form->generate_text_box("var_name", ""), 'var_name');
    $form_container->output_row("Anzeigetitel", "", $form->generate_text_box("title", $mybb->input['title']), 'title');
    $form_container->output_row("Beschreibung", "", $form->generate_text_area("description", $mybb->input['description']), 'description');
    $form_container->output_row("Feldtyp", "", $form->generate_select_box("type", [
        "text" => "Textfeld",
        "textarea" => "Mehrzeiliges Textfeld",
        "select" => "Dropdown (einfach)",
        "radio" => "Radiobuttons",
        "number" => "Zahl (mit Einheit)",
        "percent" => "Prozent/Progressbar",
        "spectrum" => "Spektrum",
        "rankscale" => "Rangauswahl (z.B. Anfänger-Meister)",
        "checkbox" => "Checkboxen (mehrfach)",
        "image_url" => "Bild-URL (extern)",
        "image_upload" => "Bild-Upload (lokal)"
    ], $mybb->input['type']), 'type');

    $unit_settings = "<div id=\"unit_label_field\" style=\"margin-bottom: 0\">";
    $unit_settings .= $form->generate_text_box("unit_label", $mybb->input['unit_label'] ?? '');
    $unit_settings .= "</div>";

    $form_container->output_row(
        "Einheit anzeigen (optional)", 
        "Wird hinter dem Zahlenwert angezeigt, z. B. „Jahre“, „kg“, „cm“ usw.", 
        $unit_settings, '', 
        ['id' => 'row_unit_label']
    );

    $spectrum_settings = "<div id=\"spectrum_options_field\">";
    $spectrum_settings .= "<p>Anzahl der Stufen (z.B. 6): " . $form->generate_text_box("option_steps", $mybb->input['option_steps'] ?? "6", ['style' => 'width:100px']) . "</p>";
    $spectrum_settings .= "<p>Linkes Label (z.B. introvertiert): " . $form->generate_text_box("option_leftlabel", $mybb->input['option_leftlabel'] ?? "", ['style' => 'width:150px']) . "</p>";
    $spectrum_settings .= "<p>Rechtes Label (z.B. extrovertiert): " . $form->generate_text_box("option_rightlabel", $mybb->input['option_rightlabel'] ?? "", ['style' => 'width:150px']) . "</p>";
    $spectrum_settings .= "</div>";


    $form_container->output_row(
        "Spektrum-Einstellungen",
        "Nur bei Spektrum-Feldern notwendig.",
        $spectrum_settings,
        '',
        ['id' => 'row_spectrum_options']
    );




   $image_settings = "<div id=\"image_options_fields\">";

    // Nur POST (im ADD gibt es noch keinen DB-Satz)
    $val_maxw = (string)($mybb->input['option_maxwidth']  ?? '');
    $val_maxh = (string)($mybb->input['option_maxheight'] ?? '');
    $val_minw = (string)($mybb->input['option_minwidth']  ?? '');
    $val_minh = (string)($mybb->input['option_minheight'] ?? '');
    $val_ph   = (string)($mybb->input['option_placeholder'] ?? '');

    $image_settings .= "<p>Max. Breite (px): " . $form->generate_text_box("option_maxwidth",  $val_maxw, ['style' => 'width:100px']) . "</p>";
    $image_settings .= "<p>Max. Höhe (px): " . $form->generate_text_box("option_maxheight", $val_maxh, ['style' => 'width:100px']) . "</p>";
    $image_settings .= "<p>Min. Breite (px): " . $form->generate_text_box("option_minwidth",  $val_minw, ['style' => 'width:100px']) . "</p>";
    $image_settings .= "<p>Min. Höhe (px): " . $form->generate_text_box("option_minheight", $val_minh, ['style' => 'width:100px']) . "</p>";

    /* Platzhalter-URL (optional) */
    $image_settings .= "<p>Platzhalter-URL (optional, für nicht-berechtigte Gruppen): " .
        $form->generate_text_box("option_placeholder", $val_ph, ['style' => 'width:100%','placeholder'=>'https://… oder /images/placeholder.png']) .
    "</p>";

    $image_settings .= "</div>";



    $form_container->output_row("Bildgrößen-Einschränkungen", "Nur bei Bildfeldern", $image_settings, '', ['id' => 'row_image_options']);


    
    $imageupload_settings = "<div id=\"imageupload_options_fields\">";

    $val_allowed = (string)($mybb->input['option_allowedtypes'] ?? '');
    $val_sizekb  = (string)($mybb->input['option_maxfilesize']  ?? '');
    $val_maxw    = (string)($mybb->input['option_maxwidth']     ?? '');
    $val_maxh    = (string)($mybb->input['option_maxheight']    ?? '');
    $val_minw    = (string)($mybb->input['option_minwidth']     ?? '');
    $val_minh    = (string)($mybb->input['option_minheight']    ?? '');
    $val_ph_up   = (string)($mybb->input['option_placeholder']  ?? '');

    $imageupload_settings .= "<p>Erlaubte Bildformate: " . $form->generate_text_box("option_allowedtypes", $val_allowed, ['style' => 'width:150px','placeholder'=>'jpg,png,gif']) . "</p>";
    $imageupload_settings .= "<p>Max. Dateigröße (KB): " . $form->generate_text_box("option_maxfilesize", $val_sizekb, ['style' => 'width:100px','placeholder'=>'2048']) . "</p>";
    $imageupload_settings .= "<p>Max. Breite (px): " . $form->generate_text_box("option_maxwidth", $val_maxw, ['style' => 'width:100px']) . "</p>";
    $imageupload_settings .= "<p>Max. Höhe (px): " . $form->generate_text_box("option_maxheight", $val_maxh, ['style' => 'width:100px']) . "</p>";
    $imageupload_settings .= "<p>Min. Breite (px): " . $form->generate_text_box("option_minwidth", $val_minw, ['style' => 'width:100px']) . "</p>";
    $imageupload_settings .= "<p>Min. Höhe (px): " . $form->generate_text_box("option_minheight", $val_minh, ['style' => 'width:100px']) . "</p>";
    $imageupload_settings .= "<p>Platzhalter-URL (optional, für nicht-berechtigte Gruppen): " .
        $form->generate_text_box("option_placeholder", $val_ph_up, ['style' => 'width:100%','placeholder'=>'https://… oder /images/placeholder.png']) .
    "</p>";

    $imageupload_settings .= "</div>";


    $form_container->output_row("Bildupload", "Nur bei Bildfeldern.", $imageupload_settings, '', ['id' => 'row_imageupload_options']);

    $options_settings = "<div id=\"options_fields\">";
    $options_settings .= $form->generate_text_area("options", $options_raw);
    $options_settings .= "</div>";

    $form_container->output_row("Optionen", "Nur bei Auswahl-Feldtypen notwendig (jede Option in eine neue Zeile)", $options_settings, '', ['id' => 'row_options']);


  // ADD: nur POST-Wert oder Default 100
    $current_max = (int)($mybb->input['option_maxvalue'] ?? 100);
    if ($current_max <= 0) { $current_max = 100; }



    $percent_settings = "<div id=\"percent_options_field\">";
    $percent_settings .= "<p>Maximalwert: " . $form->generate_text_box("option_maxvalue", (string)$current_max, ['style' => 'width:100px']) . "</p>";
    $percent_settings .= "</div>";

    $form_container->output_row("Progressbar-Einstellungen", "Nur bei Prozent-Feldern relevant.", $percent_settings, '', ['id' => 'row_percent_options']);


    $form_container->output_row("Sichtbar für Gruppen <em>(Mehrfachauswahl mit STRG)</em>", "Welche Benutzergruppen dürfen dieses Feld sehen?", $form->generate_group_select('viewablegroups[]', $mybb->input['viewablegroups'] ?? [], ['multiple' => true]));
    $form_container->output_row("Bearbeitbar für Gruppen <em>(Mehrfachauswahl mit STRG)</em>", "Welche Benutzergruppen dürfen dieses Feld bearbeiten?", $form->generate_group_select('editablegroups[]', $mybb->input['editablegroups'] ?? [], ['multiple' => true]));
    $form_container->output_row("Reihenfolge", "Zahl für Sortierung", $form->generate_text_box("disporder", $mybb->input['disporder']), 'disporder');
    $form_container->output_row("Pflichtfeld", "Dieses Feld muss ausgefüllt werden, um den Steckbrief abzusenden.", $form->generate_yes_no_radio('required', (int)$mybb->input['required']), 'required');
    /* $form_container->output_row(
        "Feld ganz ausblenden bei fehlender Berechtigung?",
        "Wenn aktiviert, wird das gesamte Feld (Label + Inhalt) nicht angezeigt, wenn der Benutzer keine Leseberechtigung hat. Wenn deaktiviert, wird nur der Inhalt ausgeblendet.",
        $form->generate_yes_no_radio('hide_fully', (int)($mybb->input['hide_fully'] ?? 0)),
        'hide_fully'
    ); */




    $form_container->end();


    $buttons[] = $form->generate_submit_button("Feld speichern");
    $form->output_submit_wrapper($buttons);
       echo <<<JS
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                const fieldType = document.querySelector("select[name='type']");
                const unitLabelField = document.getElementById("row_unit_label");
                const percentOptions = document.getElementById("row_percent_options");
                const spectrumOptions = document.getElementById("row_spectrum_options");
                const imageOptions = document.getElementById("row_image_options");
                const imageUploadOptions = document.getElementById("row_imageupload_options");
                const optionsFields = document.getElementById("row_options");

                // NEU: Alle Controls in einem Container (de)aktivieren
                function setDisabled(container, disabled) {
                    if (!container) return;
                    container.querySelectorAll("input, select, textarea").forEach(el => {
                        el.disabled = !!disabled;
                    });
                }

                function toggleFieldVisibility() {
                    const type = fieldType?.value;

                    // NEU: Erst alles verstecken & deaktivieren
                    [unitLabelField, percentOptions, spectrumOptions, imageOptions, imageUploadOptions, optionsFields].forEach(el => {
                        el?.style?.setProperty("display", "none");
                        setDisabled(el, true);
                    });

                    if (type === "image_url") {
                        imageOptions?.style?.setProperty("display", "block");
                        imageOptions?.style?.setProperty("margin-bottom", "0");
                        setDisabled(imageOptions, false);

                    } else if (type === "number") {
                        unitLabelField?.style?.setProperty("display", "block");
                        unitLabelField?.style?.setProperty("margin-bottom", "0");
                        setDisabled(unitLabelField, false);

                    } else if (type === "percent") {
                        percentOptions?.style?.setProperty("display", "block");
                        percentOptions?.style?.setProperty("margin-bottom", "0");
                        setDisabled(percentOptions, false);

                    } else if (type === "spectrum") {
                        spectrumOptions?.style?.setProperty("display", "block");
                        spectrumOptions?.style?.setProperty("margin-bottom", "0");
                        setDisabled(spectrumOptions, false);

                    } else if (type === "image_upload") {
                        imageUploadOptions?.style?.setProperty("display", "block");
                        imageUploadOptions?.style?.setProperty("margin-bottom", "0");
                        setDisabled(imageUploadOptions, false);

                    } else if (["select", "radio", "checkbox", "rankscale"].includes(type)) {
                        optionsFields?.style?.setProperty("display", "block");
                        optionsFields?.style?.setProperty("margin-bottom", "0");
                        setDisabled(optionsFields, false);
                    }
                }

                if (fieldType) {
                    fieldType.addEventListener("change", toggleFieldVisibility);
                    toggleFieldVisibility();
                }
            });
            </script>

        JS;

    $form->end();
    $page->output_footer();
    exit;
}

if ($mybb->input['action'] == "add_layout") {
    $page->add_breadcrumb_item("Neues Layout-Element");

    if (!isset($mybb->input['disporder']) || $mybb->input['disporder'] === '') {
        $max_disporder = (int)$db->fetch_field(
            $db->simple_select("charactersheet_fields", "MAX(disporder) AS max_disporder"),
            "max_disporder"
        );
        $mybb->input['disporder'] = $max_disporder + 1;
    }


    if ($mybb->request_method == "post") {
        $new_layout = [ 
            'field_scope'     => 'layout',
            'type'      => $db->escape_string($mybb->input['type']),
            'var_name'      => $db->escape_string($mybb->input['var_name']),
            'title'           => $db->escape_string($mybb->input['title']),
            'description'     => $db->escape_string($mybb->input['description']),
            'options'         => '',
            'viewablegroups' => $db->escape_string(implode(',', (array)$mybb->input['viewablegroups'])),
            'editablegroups' => $db->escape_string(implode(',', (array)$mybb->input['editablegroups'])),
            'required'        => 0,
            'disporder' => (int)$mybb->input['disporder'] 
        ];



        $db->insert_query('charactersheet_fields', $new_layout);
        flash_message("Layout-Element gespeichert.", "success");
        admin_redirect("index.php?module=config-charactersheet");
    }

    $form = new Form("index.php?module=config-charactersheet&amp;action=add_layout", "post");
    $form_container = new FormContainer("Layout-Element anlegen");

   $form_container->output_row("Typ", "", $form->generate_select_box("type", [
    "layout_heading" => "Hauptüberschrift",
    "layout_subheading" => "Zwischenüberschrift",
    "layout_info" => "Beschreibungstext",
    ], $mybb->input['type'], ['style' => 'width: 45%;']), 'type');

    $form_container->output_row("Variablenname", "Name der Variable. Nur Kleinbuchstaben, Zahlen und Unterstrich!", $form->generate_text_box("var_name", $mybb->input['var_name'] ?? $field['var_name'], ['style' => 'width: 96%;']), 'var_name');
    $form_container->output_row("Feldname", "Interner Bezeichner für das Template für die Variable", $form->generate_text_box("title", $mybb->input['title'] ?? $field['title'], ['style' => 'width: 96%;']), 'title');

    $description_value = htmlspecialchars($mybb->input['description'] ?? $field['description'] ?? '');

    $form_container->output_row(
        "Inhalt",
        "Der Inhalt wird entweder bei der Eingabe oder Ausgabe angezeigt",
        '<div id="description_wrapper">
            <input type="text" name="description" value="' . $description_value . '" style="width: 96%;" />
        </div>',
        'description'
    );


    $form_container->output_row(
    "Sichtbar für Gruppen (Ausfüllen) <em>(Mehrfachauswahl mit STRG)</em>",
    "Welche Benutzergruppen dürfen dieses Layout-Element im Ausfüllformular sehen?",
    $form->generate_group_select('editablegroups[]', $mybb->input['editablegroups'] ?? [], ['multiple' => true])
);


    $form_container->output_row(
        "Sichtbar für Gruppen (Ansicht) <em>(Mehrfachauswahl mit STRG)</em>",
        "Welche Benutzergruppen dürfen dieses Feld sehen?",
        $form->generate_group_select('viewablegroups[]', $mybb->input['viewablegroups'] ?? [], ['multiple' => true])
    );


    $form_container->output_row("Reihenfolge", "Zahl für Sortierung", $form->generate_text_box("disporder", $mybb->input['disporder']), 'disporder');


    $form_container->end();

    $buttons[] = $form->generate_submit_button("Layout-Element speichern");
    $form->output_submit_wrapper($buttons);
    $form->end();

        echo <<<JS
        <script>
        document.addEventListener("DOMContentLoaded", function() {
            const typeSelect = document.querySelector("select[name='type']");
            const wrapper = document.querySelector("#description_wrapper");

            function renderDescriptionField() {
                // aktuellen Wert aus vorhandenem Feld holen (falls vorhanden)
                const oldField = wrapper.querySelector("[name='description']");
                const currentValue = oldField ? oldField.value : "";

                const isMultiline = typeSelect.value === "layout_info";

                const newField = document.createElement(isMultiline ? "textarea" : "input");
                newField.name = "description";
                newField.style.width = "96%";

                if (isMultiline) {
                    newField.rows = 4;
                } else {
                    newField.type = "text";
                }

                newField.value = currentValue;

                wrapper.innerHTML = '';
                wrapper.appendChild(newField);
            }

            if (typeSelect && wrapper) {
                typeSelect.addEventListener("change", renderDescriptionField);
                renderDescriptionField(); // Initial render bei Erstellen & Editieren
            }
        });
        </script>
        JS;






    $page->output_footer();
    exit;
}


if ($mybb->input['action'] == "edit") {
    $fid = (int)$mybb->input['fid'];

    $field = $db->fetch_array($db->simple_select("charactersheet_fields", "*", "fid='{$fid}'"));
    $image_options = [
    'allowedtypes' => '',
    'maxfilesize' => '',
    'maxwidth' => '',
    'maxheight' => '',
    'minwidth' => '',
    'minheight' => '',
    'placeholder' => '',
        ];

        // Optionen für Textfeld vorbereiten (vermeide JSON im Optionsfeld)
        $options_raw = $field['options'];
        if (in_array($field['type'], ['image_url', 'image_upload'])) {
            $options_raw = ''; // bei Bildtypen: keine Textoptionen anzeigen
        }


        if ($field['type'] == 'image_url' && $field['options']) {
            $decoded = json_decode($field['options'], true);
            if (is_array($decoded)) {
                $image_options = array_merge($image_options, $decoded);
            }
        }

        if ($field['type'] == 'image_upload' && $field['options']) {
            $decoded = json_decode($field['options'], true);
            if (is_array($decoded)) {
                $image_options = array_merge($image_options, $decoded);
            }
        }


    // Spektrum-Werte auslesen
        $spectrum_options = [
            'steps' => 6,
            'leftlabel' => '',
            'rightlabel' => ''
        ];

        if ($field['type'] == 'spectrum' && $field['options']) {
            $decoded = json_decode($field['options'], true);
            if (is_array($decoded)) {
                $spectrum_options = array_merge($spectrum_options, $decoded);
            }
        }



    if (!$field) {
        flash_message("Feld nicht gefunden.", "error");
        admin_redirect("index.php?module=config-charactersheet");
    }

    $page->add_breadcrumb_item("Feld bearbeiten");

    // Wenn gespeichert wird
    if ($mybb->request_method == "post") {

        $viewablegroups = implode(',', array_map('intval', (array)$mybb->input['viewablegroups']));
        $editablegroups = implode(',', array_map('intval', (array)$mybb->input['editablegroups']));
        if ($mybb->input['type'] == 'image_url') {
            $options = json_encode([
                'maxwidth' => (int)$mybb->input['option_maxwidth'],
                'maxheight' => (int)$mybb->input['option_maxheight'],
                'minwidth' => (int)$mybb->input['option_minwidth'],
                'minheight' => (int)$mybb->input['option_minheight'],
                'placeholder'=> trim((string)$mybb->input['option_placeholder']),
            ]);
        } elseif ($mybb->input['type'] == 'image_upload') {
            // NEU: allowedtypes robust normalisieren (z. B. ".PNG, jpg  ; gif" -> "png,jpg,gif")
            $allowedtypes_raw = (string)$mybb->input['option_allowedtypes'];
            $allowedtypes_arr = preg_split('/[,\s;|]+/', $allowedtypes_raw, -1, PREG_SPLIT_NO_EMPTY);
            $allowedtypes_arr = array_map(function($x) {
                $x = strtolower(trim($x));
                $x = ltrim($x, '.'); // führenden Punkt entfernen
                return $x;
            }, $allowedtypes_arr);
            $allowedtypes_arr = array_values(array_unique(array_filter($allowedtypes_arr)));
            $allowedtypes_norm = implode(',', $allowedtypes_arr);

            $options = json_encode([
                'allowedtypes' => $allowedtypes_norm,
                'maxfilesize'  => (int)$mybb->input['option_maxfilesize'],
                'maxwidth'     => (int)$mybb->input['option_maxwidth'],
                'maxheight'    => (int)$mybb->input['option_maxheight'],
                'minwidth'     => (int)$mybb->input['option_minwidth'],
                'minheight'    => (int)$mybb->input['option_minheight'],
                'placeholder'  => trim((string)$mybb->input['option_placeholder']),
            ]);
        } elseif ($mybb->input['type'] == 'percent') {
                    $options = (int)$mybb->input['option_maxvalue'];
                } elseif ($mybb->input['type'] == 'spectrum') {
                    $options = json_encode([
                        'steps' => (int)$mybb->input['option_steps'],
                        'leftlabel' => $mybb->input['option_leftlabel'],
                        'rightlabel' => $mybb->input['option_rightlabel'],
                    ]);
        } else {
            $options = str_replace("\r", '', $mybb->input['options']);
        }



        $updated_field = [
            'var_name' => $db->escape_string($mybb->input['var_name']),
            'title' => $db->escape_string($mybb->input['title']),
            'description' => $db->escape_string($mybb->get_input('description', MyBB::INPUT_STRING)),
            'type' => $db->escape_string($mybb->input['type']),
            'options' => $db->escape_string($options),
            'unit_label' => $db->escape_string($mybb->input['unit_label']),
            'viewablegroups' => $db->escape_string(implode(',', (array)$mybb->input['viewablegroups'])),
            'editablegroups' => $db->escape_string(implode(',', (array)$mybb->input['editablegroups'])),
            'disporder' => (int)$mybb->input['disporder'],
            // 'hide_fully' => (int)$mybb->input['hide_fully'],
            'required' => (int)$mybb->input['required']
        ];

        $db->update_query("charactersheet_fields", $updated_field, "fid='{$fid}'");

        flash_message("Feld erfolgreich aktualisiert.", "success");
        admin_redirect("index.php?module=config-charactersheet");
    }

    $required = (int)$field['required'] ?? 0;

    $form = new Form("index.php?module=config-charactersheet&amp;action=edit&amp;fid={$fid}", "post");
    $form_container = new FormContainer('Feld bearbeiten', [], 'general_form_container');

    $form_container->output_row("Interner Feldname", "", $form->generate_text_box("var_name", $field['var_name']), 'var_name');
    $form_container->output_row("Anzeigetitel", "", $form->generate_text_box("title", $field['title']), 'title');
    $form_container->output_row("Beschreibung", "", $form->generate_text_area("description", $field['description']), 'description');
    $form_container->output_row("Feldtyp", "", $form->generate_select_box("type", [
        "text" => "Textfeld",
        "textarea" => "Mehrzeiliges Textfeld",
        "select" => "Dropdown (einfach)",
        "radio" => "Radiobuttons (einfach)",
        "number" => "Zahl (mit Einheit)",
        "percent" => "Prozent/Progressbar",
        "spectrum" => "Spektrum",
        "rankscale" => "Rangauswahl (z.B. Anfänger-Meister)",
        "checkbox" => "Checkboxen (mehrfach)",
        "image_url" => "Bild-URL (extern)",
        "image_upload" => "Bild-Upload (lokal)"
    ], $field['type']), 'type');

    $type = $mybb->input['type'] ?? $field['type'] ?? '';

    $unit_settings = "<div id=\"unit_label_field\" style=\"margin-bottom: 0\">";
    $unit_settings .= $form->generate_text_box("unit_label", $mybb->input['unit_label'] ?? '');
    $unit_settings .= "</div>";

    $form_container->output_row(
            "Einheit anzeigen (optional)","Wird hinter dem Zahlenwert angezeigt, z.B. „Jahre“, „kg“, „cm“ usw.", 
            $unit_settings, 
            '', 
            ['id' => 'row_unit_label']
    );

    $spectrum_settings = "<div id=\"spectrum_options_field\">";
    $spectrum_settings .= "<p>Anzahl der Stufen (z.B. 6): " . $form->generate_text_box("option_steps", (int)$spectrum_options['steps'], ['style' => 'width:100px']) . "</p>";
    $spectrum_settings .= "<p>Linkes Label (z.B. introvertiert): " . $form->generate_text_box("option_leftlabel", $spectrum_options['leftlabel'], ['style' => 'width:150px']) . "</p>";
    $spectrum_settings .= "<p>Rechtes Label (z.B. extrovertiert): " . $form->generate_text_box("option_rightlabel", $spectrum_options['rightlabel'], ['style' => 'width:150px']) . "</p>";
    $spectrum_settings .= "</div>";



    $form_container->output_row(
        "Spektrum-Einstellungen",
        "Nur bei Spektrum-Feldern notwendig.",
        $spectrum_settings,
        '',
        ['id' => 'row_spectrum_options']
    );


    $image_settings = "<div id=\"image_options_fields\">";

    // POST bevorzugen, sonst DB
    $val_maxw = ($mybb->request_method === 'post') ? (string)$mybb->input['option_maxwidth']  : (string)($image_options['maxwidth']  ?? '');
    $val_maxh = ($mybb->request_method === 'post') ? (string)$mybb->input['option_maxheight'] : (string)($image_options['maxheight'] ?? '');
    $val_minw = ($mybb->request_method === 'post') ? (string)$mybb->input['option_minwidth']  : (string)($image_options['minwidth']  ?? '');
    $val_minh = ($mybb->request_method === 'post') ? (string)$mybb->input['option_minheight'] : (string)($image_options['minheight'] ?? '');
    $val_ph   = ($mybb->request_method === 'post') ? (string)$mybb->input['option_placeholder'] : (string)($image_options['placeholder'] ?? '');

    $image_settings .= "<p>Max. Breite (px): " . $form->generate_text_box("option_maxwidth",  $val_maxw, ['style' => 'width:100px']) . "</p>";
    $image_settings .= "<p>Max. Höhe (px): " . $form->generate_text_box("option_maxheight", $val_maxh, ['style' => 'width:100px']) . "</p>";
    $image_settings .= "<p>Min. Breite (px): " . $form->generate_text_box("option_minwidth",  $val_minw, ['style' => 'width:100px']) . "</p>";
    $image_settings .= "<p>Min. Höhe (px): " . $form->generate_text_box("option_minheight", $val_minh, ['style' => 'width:100px']) . "</p>";

    /* NEU: Platzhalter */
    $image_settings .= "<p>Platzhalter-URL (optional, für nicht-berechtigte Gruppen): " .
        $form->generate_text_box("option_placeholder", $val_ph, ['style' => 'width:100%','placeholder'=>'https://… oder /images/placeholder.png']) .
    "</p>";

    $image_settings .= "</div>";

    $form_container->output_row(
        "Bildgrößen-Einschränkungen",
        "Nur bei Bildfeldern",
        $image_settings,
        '',
        ['id' => 'row_image_options']
    );


    
    $imageupload_settings = "<div id=\"imageupload_options_fields\">";

    // POST bevorzugen, sonst DB ($image_options wird oben befüllt)
    $val_allowed = ($mybb->request_method === 'post') ? (string)$mybb->input['option_allowedtypes'] : (string)($image_options['allowedtypes'] ?? '');
    $val_sizekb  = ($mybb->request_method === 'post') ? (string)$mybb->input['option_maxfilesize']  : (string)($image_options['maxfilesize'] ?? '');
    $val_maxw    = ($mybb->request_method === 'post') ? (string)$mybb->input['option_maxwidth']     : (string)($image_options['maxwidth'] ?? '');
    $val_maxh    = ($mybb->request_method === 'post') ? (string)$mybb->input['option_maxheight']    : (string)($image_options['maxheight'] ?? '');
    $val_minw    = ($mybb->request_method === 'post') ? (string)$mybb->input['option_minwidth']     : (string)($image_options['minwidth'] ?? '');
    $val_minh    = ($mybb->request_method === 'post') ? (string)$mybb->input['option_minheight']    : (string)($image_options['minheight'] ?? '');
    $val_ph_up   = ($mybb->request_method === 'post') ? (string)$mybb->input['option_placeholder']  : (string)($image_options['placeholder'] ?? '');

    $imageupload_settings .= "<p>Erlaubte Bildformate: " . $form->generate_text_box("option_allowedtypes", $val_allowed, ['style' => 'width:150px','placeholder'=>'jpg,png,gif']) . "</p>";
    $imageupload_settings .= "<p>Max. Dateigröße (KB): " . $form->generate_text_box("option_maxfilesize", $val_sizekb, ['style' => 'width:100px','placeholder'=>'2048']) . "</p>";
    $imageupload_settings .= "<p>Max. Breite (px): " . $form->generate_text_box("option_maxwidth", $val_maxw, ['style' => 'width:100px']) . "</p>";
    $imageupload_settings .= "<p>Max. Höhe (px): " . $form->generate_text_box("option_maxheight", $val_maxh, ['style' => 'width:100px']) . "</p>";
    $imageupload_settings .= "<p>Min. Breite (px): " . $form->generate_text_box("option_minwidth", $val_minw, ['style' => 'width:100px']) . "</p>";
    $imageupload_settings .= "<p>Min. Höhe (px): " . $form->generate_text_box("option_minheight", $val_minh, ['style' => 'width:100px']) . "</p>";

    /* NEU: Platzhalter-URL */
    $imageupload_settings .= "<p>Platzhalter-URL (optional, für nicht-berechtigte Gruppen): " .
        $form->generate_text_box("option_placeholder", $val_ph_up, ['style' => 'width:100%','placeholder'=>'https://… oder /images/placeholder.png']) .
    "</p>";

    $imageupload_settings .= "</div>";
    


    $form_container->output_row(
        "Bildupload",
        "Nur bei Bildfeldern.",
        $imageupload_settings,
        '',
        ['id' => 'row_imageupload_options']
    );

    $options_settings = "<div id=\"options_fields\">";
    $options_settings .= $form->generate_text_area("options", $options_raw);
    $options_settings .= "</div>";

    $form_container->output_row("Optionen", "Nur bei Auswahl-Feldtypen notwendig (jede Option in eine neue Zeile)", $options_settings, '', ['id' => 'row_options']);

    // Aktuellen Max-Wert robust aus der DB lesen (unterstützt "9" oder "max=9")
    $fid = (int)$mybb->get_input('fid');
    $current_max = 100;
    if ($fid > 0) {
        $row = $db->fetch_array($db->simple_select(
            "charactersheet_fields",
            "type, options",
            "fid={$fid}",
            ["limit" => 1]
        ));
        if (!empty($row) && $row['type'] === 'percent') {
            $optstr = trim((string)$row['options']);
            if ($optstr !== '' && ctype_digit($optstr)) {
                $current_max = max(1, (int)$optstr);
            } else {
                $opts = parse_ini_string($optstr);
                $current_max = max(1, (int)($opts['max'] ?? 100));
            }
        }
    }
    // Bei POST erneut den eingegebenen Wert zeigen (Validierungsfehler etc.)
    if ($mybb->request_method === 'post') {
        $pm = (int)$mybb->get_input('option_maxvalue');
        if ($pm > 0) { $current_max = $pm; }
    }


    $percent_settings = "<div id=\"percent_options_field\">";
    $percent_settings .= "<p>Maximalwert: " . $form->generate_text_box("option_maxvalue", (string)$current_max, ['style' => 'width:100px']) . "</p>";
    $percent_settings .= "</div>";

    $form_container->output_row("Progressbar-Einstellungen", "Nur bei Prozent-Feldern relevant.", $percent_settings, '', ['id' => 'row_percent_options']);



    $form_container->output_row("Sichtbar für Gruppen <em>(Mehrfachauswahl mit STRG)</em>", "Welche Benutzergruppen dürfen dieses Feld sehen?", $form->generate_group_select('viewablegroups[]', explode(',', $field['viewablegroups']), ['multiple' => true]));
    $form_container->output_row("Bearbeitbar für Gruppen <em>(Mehrfachauswahl mit STRG)</em>", "Welche Benutzergruppen dürfen dieses Feld bearbeiten?", $form->generate_group_select('editablegroups[]', explode(',', $field['editablegroups']), ['multiple' => true]));
    $form_container->output_row("Reihenfolge", "", $form->generate_text_box("disporder", $field['disporder']), 'disporder');
    $form_container->output_row("Pflichtfeld", "Dieses Feld muss ausgefüllt werden, um den Steckbrief abzusenden.", $form->generate_yes_no_radio("required", $required), 'required');
    /* $form_container->output_row(
        "Ganzes Feld ausblenden, wenn keine Berechtigung vorliegt?",
        "Wenn aktiv, wird das komplette Feld (Label + Inhalt) nicht angezeigt.",
        $form->generate_yes_no_radio("hide_fully", (int)($field['hide_fully'] ?? 0)),
        'hide_fully'
    ); */


    $form_container->end();
    $buttons[] = $form->generate_submit_button("Speichern");
    $form->output_submit_wrapper($buttons);
    $form->end();
        echo <<<JS
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                const fieldType = document.querySelector("select[name='type']");
                const unitLabelField = document.getElementById("row_unit_label");
                const percentOptions = document.getElementById("row_percent_options");
                const spectrumOptions = document.getElementById("row_spectrum_options");
                const imageOptions = document.getElementById("row_image_options");
                const imageUploadOptions = document.getElementById("row_imageupload_options");
                const optionsFields = document.getElementById("row_options");

                // NEU: Alle Controls in einem Container (de)aktivieren
                function setDisabled(container, disabled) {
                    if (!container) return;
                    container.querySelectorAll("input, select, textarea").forEach(el => {
                        el.disabled = !!disabled;
                    });
                }

                function toggleFieldVisibility() {
                    const type = fieldType?.value;

                    // NEU: Erst alles verstecken & deaktivieren
                    [unitLabelField, percentOptions, spectrumOptions, imageOptions, imageUploadOptions, optionsFields].forEach(el => {
                        el?.style?.setProperty("display", "none");
                        setDisabled(el, true);
                    });

                    if (type === "image_url") {
                        imageOptions?.style?.setProperty("display", "block");
                        imageOptions?.style?.setProperty("margin-bottom", "0");
                        setDisabled(imageOptions, false);

                    } else if (type === "number") {
                        unitLabelField?.style?.setProperty("display", "block");
                        unitLabelField?.style?.setProperty("margin-bottom", "0");
                        setDisabled(unitLabelField, false);

                    } else if (type === "percent") {
                        percentOptions?.style?.setProperty("display", "block");
                        percentOptions?.style?.setProperty("margin-bottom", "0");
                        setDisabled(percentOptions, false);

                    } else if (type === "spectrum") {
                        spectrumOptions?.style?.setProperty("display", "block");
                        spectrumOptions?.style?.setProperty("margin-bottom", "0");
                        setDisabled(spectrumOptions, false);

                    } else if (type === "image_upload") {
                        imageUploadOptions?.style?.setProperty("display", "block");
                        imageUploadOptions?.style?.setProperty("margin-bottom", "0");
                        setDisabled(imageUploadOptions, false);

                    } else if (["select", "radio", "checkbox", "rankscale"].includes(type)) {
                        optionsFields?.style?.setProperty("display", "block");
                        optionsFields?.style?.setProperty("margin-bottom", "0");
                        setDisabled(optionsFields, false);
                    }
                }

                if (fieldType) {
                    fieldType.addEventListener("change", toggleFieldVisibility);
                    toggleFieldVisibility();
                }
            });
            </script>

        JS;



    $page->output_footer();
    exit;
}


if ($mybb->input['action'] == "edit_layout") {
    $fid = (int)$mybb->input['fid'];
    $field = $db->fetch_array($db->simple_select("charactersheet_fields", "*", "fid='{$fid}'"));
    if (!$field || $field['field_scope'] !== 'layout') {
        flash_message("Layout-Element nicht gefunden.", "error");
        admin_redirect("index.php?module=config-charactersheet");
    }

    $page->add_breadcrumb_item("Layout-Element bearbeiten");

    if ($mybb->request_method == "post") {

        $updated = [
            'var_name' => $db->escape_string($mybb->input['var_name']),
            'title' => $db->escape_string($mybb->input['title']),
            'description' => $db->escape_string($mybb->input['description']),
            'type' => $db->escape_string($mybb->input['type']),
            'disporder' => (int)$mybb->input['disporder'],
            'editablegroups' => $db->escape_string(implode(',', (array)$mybb->input['editablegroups'] ?? [])),
            'viewablegroups' => $db->escape_string(implode(',', (array)$mybb->input['viewablegroups'] ?? [])),
        ];

        $db->update_query("charactersheet_fields", $updated, "fid='{$fid}'");
        flash_message("Layout-Element aktualisiert.", "success");
        admin_redirect("index.php?module=config-charactersheet");
    }

    $form = new Form("index.php?module=config-charactersheet&amp;action=edit_layout&amp;fid={$fid}", "post");
    $form_container = new FormContainer("Layout-Element bearbeiten");

    $form_container->output_row("Feldtitle", "Name der Variable in der Template. Nur Kleinbuchstaben und _ und - erlaubt!", $form->generate_text_box("var_name", $mybb->input['var_name'] ?? $field['var_name'], ['style' => 'width: 96%;']), 'var_name');

    $form_container->output_row("Feldname", "Name des Feldes, der in der ACP-Übersicht angezeigt wird.", $form->generate_text_box("title", $mybb->input['title'] ?? $field['title'], ['style' => 'width: 96%;']), 'title');

    
    $form_container->output_row("Typ", "", $form->generate_select_box("type", [
        "layout_heading" => "Hauptüberschrift",
        "layout_subheading" => "Zwischenüberschrift",
        "layout_info" => "Beschreibungstext",
    ], $mybb->input['type'] ?? $field['type'], ['style' => 'width: 45%;']), 'type');

   $form_container->output_row(
        "Inhalt",
        "Der Inhalt wird entweder bei der Eingabe oder Ausgabe angezeigt",
        '<div id="description_wrapper">
            <input type="text" name="description" value="' . htmlspecialchars($mybb->input['description'] ?? $field['description'] ?? '') . '" style="width: 96%;" />
        </div>',
        'description'
    );




    $form_container->output_row("Reihenfolge", "", $form->generate_text_box("disporder", $field['disporder'], ['style' => 'width: 50px']), 'disporder');

    $form_container->output_row(
        "Bearbeitbar für Gruppen <em>(Mehrfachauswahl mit STRG)</em>",
        "Wer darf das Element im Ausfüllformular sehen?",
        $form->generate_group_select('editablegroups[]', explode(',', $field['editablegroups']), ['multiple' => true])
    );

    $form_container->output_row(
        "Sichtbar in Ansicht für Gruppen <em>(Mehrfachauswahl mit STRG)</em>",
        "Wer darf das Element im Steckbrief sehen?",
        $form->generate_group_select('viewablegroups[]', explode(',', $field['viewablegroups']), ['multiple' => true])
    );

    $form_container->end();
    $buttons[] = $form->generate_submit_button("Speichern");
    $form->output_submit_wrapper($buttons);
    $form->end();

    $escaped_value = json_encode($mybb->input['description'] ?? $field['description'] ?? "");


        echo <<<JS
            <script>
            document.addEventListener("DOMContentLoaded", function() {
                const typeSelect = document.querySelector("select[name='type']");
                const wrapper = document.querySelector("#description_wrapper");
                const initialValue = $escaped_value;

                function renderDescriptionField() {
                    // Bestehenden Wert holen
                    const oldField = wrapper.querySelector("[name='description']");
                    const currentValue = oldField ? oldField.value : initialValue;

                    const isMultiline = typeSelect.value === "layout_info";

                    const newField = document.createElement(isMultiline ? "textarea" : "input");
                    newField.name = "description";
                    newField.style.width = "96%";

                    if (isMultiline) {
                        newField.rows = 4;
                        newField.value = currentValue;
                    } else {
                        newField.type = "text";
                        newField.value = currentValue;
                    }

                    wrapper.innerHTML = '';
                    wrapper.appendChild(newField);
                }

                if (typeSelect && wrapper) {
                    typeSelect.addEventListener("change", renderDescriptionField);
                    renderDescriptionField();
                }
            });
            </script>
            JS;




    $page->output_footer();
    exit;
}




// Felder löschen
if ($mybb->input['action'] == "delete") {
    $fid = (int)$mybb->input['fid'];

    // Feld prüfen
    $field = $db->fetch_array($db->simple_select("charactersheet_fields", "*", "fid='{$fid}'"));
    if (!$field) {
        flash_message("Feld nicht gefunden.", "error");
        admin_redirect("index.php?module=config-charactersheet");
    }

    // Sicherheitsabfrage
    if ($mybb->input['confirm'] !== "yes") {
        $page->output_confirm_action(
            "index.php?module=config-charactersheet&amp;action=delete&amp;fid={$fid}&amp;confirm=yes",
            "Möchtest du das Feld <strong>".htmlspecialchars_uni($field['title'])."</strong> wirklich löschen?<br />Alle dazugehörigen Werte werden entfernt.",
            "Feld löschen"
        );
    } else {
        // Löschen
        $db->delete_query("charactersheet_fields", "fid='{$fid}'");
        $db->delete_query("charactersheet_values", "fid='{$fid}'");

        flash_message("Feld erfolgreich gelöscht.", "success");
        admin_redirect("index.php?module=config-charactersheet");
    }

    $page->output_footer();
    exit;
}


// Menü aktiv setzen
$sub_tabs['overview'] = [
    'title' => "Charakterbogen verwalten",
    'link' => "index.php?module=config-charactersheet",
    'description' => "Verwalte alle Felder des Charakterbogens."
];

$page->output_nav_tabs($sub_tabs, 'overview');

function format_group_list($group_string, $groupnames) {
    $ids = explode(",", $group_string);
    $names = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if (isset($groupnames[$id])) {
            $names[] = $groupnames[$id];
        }
    }
    return implode(", ", $names);
}


// Tabelle anzeigen
$table = new Table;
$table->construct_header("Variablenname");
$table->construct_header("Feldtitel");
$table->construct_header("Typ");
$table->construct_header("Optionen");
$table->construct_header("Beschreibung");
$table->construct_header("Sichtbar für");
$table->construct_header("Bearbeitbar von");
$table->construct_header("Pflichtfeld");
$table->construct_header("Reihenfolge");
$table->construct_header("Aktionen", ["colspan" => 3]);

$query = $db->simple_select("charactersheet_fields", "*", "", ["order_by" => "disporder"]);

$groupnames = [];
$query_groups = $db->simple_select("usergroups", "gid, title");
while ($group = $db->fetch_array($query_groups)) {
    $groupnames[$group['gid']] = $group['title'];
}


while ($field = $db->fetch_array($query)) {
    $table->construct_cell(htmlspecialchars($field['var_name']));
    $table->construct_cell(htmlspecialchars($field['title']));
    $table->construct_cell(htmlspecialchars($field['type']));
    
    // Felderanzeige im ACP nach Typ angepasst
    if ($field['type'] === 'spectrum') {
        $pretty = cs_format_spectrum_options($field['options']);
        $table->construct_cell($pretty);

    } elseif ($field['type'] === 'image_url') {
        $pretty = cs_format_image_url_options($field['options']);
        $table->construct_cell($pretty);

    } elseif ($field['type'] === 'image_upload') {
        $pretty = cs_format_image_upload_options($field['options']);
        $table->construct_cell($pretty);

    } elseif ($field['type'] === 'percent') {
        $pretty = cs_format_percent_options($field['options']);
        $table->construct_cell($pretty);

    } elseif (
        $field['type'] === 'checkbox' ||
        $field['type'] === 'checkboxes' ||
        $field['type'] === 'multiselect' ||
        $field['type'] === 'multi' ||
        $field['type'] === 'select' ||
        $field['type'] === 'options' ||
        $field['type'] === 'radio' ||
        $field['type'] === 'rankscale'
    ) 
    {
        $pretty = cs_format_multi_options($field['options']);
        $table->construct_cell($pretty);

    } else {
        // Fallback roh
        $table->construct_cell(htmlspecialchars_uni($field['options']));
    }


    $table->construct_cell($field['description']);
    $table->construct_cell(format_group_list($field['viewablegroups'], $groupnames));
    $table->construct_cell(format_group_list($field['editablegroups'], $groupnames));
    $cell = '';
    if ($field['required']) {
        $cell .= '✅';
    }
    /* if ((int)$field['hide_fully'] === 1) {
        $cell .= ' <span title="Komplett ausgeblendet für unberechtigte Nutzer">🙈</span>';
    } */
    if ($cell === '') {
        $cell = '—';
    }
    $table->construct_cell($cell);

    $table->construct_cell(htmlspecialchars($field['disporder']));
    if ($field['field_scope'] === 'layout') {
        $edit_link = "edit_layout";
    } else {
        $edit_link = "edit";
    }
    $table->construct_cell("
        <a href='index.php?module=config-charactersheet&action={$edit_link}&fid={$field['fid']}'>Bearbeiten</a>
    <br> <a href=\"index.php?module=config-charactersheet&amp;action=duplicate&amp;fid={$field['fid']}\">Duplizieren</a>
    <br> <a href='index.php?module=config-charactersheet&action=delete&fid={$field['fid']}'>Löschen</a>");
    $table->construct_row();
}

$table->output("Felder des Charakterbogens");

// Links zum Hinzufügen
echo "<div style='display: flex; gap: 20px;'>
<div style='margin-top: 20px;'><button class='form_button_wrapper' style=' padding: 10px;'><a href='index.php?module=config-charactersheet&action=add' class='button'>Neues Feld hinzufügen</a></button></div>
<div style='margin-top: 20px;'><button class='form_button_wrapper' style=' padding: 10px;'><a href='index.php?module=config-charactersheet&action=add_layout' class='button'>Neues Layout-Element hinzufügen</a></button></div>
<div style='margin-top: 20px;'><button class='form_button_wrapper' style=' padding: 10px;'><a href='index.php?module=config-charactersheet&action=reorder' class='button'>Reihenfolge der Felder ändern</a></button></div>
<div style='margin-top: 20px;'><button class='form_button_wrapper' style=' padding: 10px;'><a href='index.php?module=config-charactersheet&action=docs' class='button'>Anleitung & Variablen</a></button></div>
<div style='margin-top: 20px;'><button class='form_button_wrapper' style=' padding: 10px;'><a href='index.php?module=config-charactersheet&action=export_fields'>Felder exportieren (XML)</a></button></div>
<div style='margin-top: 20px;'><button class='form_button_wrapper' style=' padding: 10px;'><a href='index.php?module=config-charactersheet&action=import_fields'>Felder importieren (XML)</a></button></div>
</div>";


$page->output_footer();


