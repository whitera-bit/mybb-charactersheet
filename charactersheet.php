<?php

define("IN_MYBB", 1);
define("THIS_SCRIPT", "charactersheet.php");

require_once "./global.php";

// Plugin geladen?
if (!function_exists('charactersheet_info')) {
    error("Das Charactersheet-Plugin ist deaktiviert. Bitte aktiviere das Plugin über das Admin Control Panel (ACP).");
}

$action = $mybb->get_input('action');
$uid = (int)$mybb->get_input('uid');

global $header, $footer, $headerinclude, $theme, $templates;

// Ansichtsrechte aufbauen
function cs_user_in_allowed_groups(string $allowed_list): bool
{
    global $mybb;

    if (trim($allowed_list) === '-1') {
        return true;
    }

    $usergroups = [$mybb->user['usergroup']];
    if (!empty($mybb->user['additionalgroups'])) {
        $usergroups = array_merge($usergroups, explode(',', $mybb->user['additionalgroups']));
    }

    $allowed = array_map('trim', explode(',', $allowed_list));

    foreach ($usergroups as $group) {
        if (in_array($group, $allowed)) {
            return true;
        }
    }

    return false;
}

// Feldansichtsrechte aufbauen
function cs_field_is_visible(array $field, ?array $usergroups = null): bool
{
    global $mybb;

    // -1 = alle dürfen sehen
    if (trim($field['viewablegroups']) === '-1') {
        return true;
    }

    // Gast-Fallback
    if ($usergroups === null) {
        if ($mybb->user['uid']) {
            $usergroups = [$mybb->user['usergroup']];
            if (!empty($mybb->user['additionalgroups'])) {
                $usergroups = array_merge($usergroups, explode(',', $mybb->user['additionalgroups']));
            }
        } else {
            $usergroups = [1];
        }
    }

    $allowed = array_map('trim', explode(',', $field['viewablegroups']));
    foreach ($usergroups as $group) {
        if (in_array($group, $allowed)) {
            return true;
        }
    }

    return false;
}




// Ausgabeseite anzeigen
if ($action == "view") {

    if (!cs_user_in_allowed_groups($mybb->settings['charactersheet_viewgroups'])) {
        error_no_permission();
    }


    
    if ($uid <= 0) {
        error("Kein Benutzer angegeben.");
    }

    // Felder laden
    $fields = [];
    $query = $db->simple_select("charactersheet_fields", "*", "", ["order_by" => "disporder"]);
    while ($field = $db->fetch_array($query)) {
        $fields[$field['var_name']] = $field;
    }

    // Werte des Nutzers laden
    $values = [];
    $query = $db->simple_select("charactersheet_values", "*", "uid = '{$uid}'");
    while ($row = $db->fetch_array($query)) {
        $values[(int)$row['fid']] = $row['value'];
    }

    // Benutzergruppen ermitteln (für Sichtbarkeitsprüfung)
    $usergroups = [$mybb->user['usergroup']];
    if (!empty($mybb->user['additionalgroups'])) {
        $usergroups = array_merge($usergroups, explode(',', $mybb->user['additionalgroups']));
    }

    // Ausgabevariablen setzen
    foreach ($fields as $name => $field) {

        if (!cs_field_is_visible($field, $usergroups)) {
            continue;
        }

        $fid = (int)$field['fid'];
        $value = $values[$fid] ?? '';
        $varname = 'charactersheet_view_' . $name;
        $charactersheet_view_layout = '';


        // Sonderfall: Layout Felder (Heading, Subheading, Information)
       if ($field['field_scope'] == 'layout') {
            $varname = 'charactersheet_view_' . $name;
            global $$varname;
            $$varname = htmlspecialchars_uni($field['description']);
        }



        // Sonderfall: Zahlenfeld mit Einheit
        if ($field['type'] == 'number') {
            $unit = htmlspecialchars_uni($field['unit_label']);
            $val = htmlspecialchars_uni($value);
            $$varname = $val . ($unit ? " {$unit}" : '');
            global $$varname;
        }

        //Sonderfall: Prozent
        if ($field['type'] == 'percent') {
            $optstr = trim((string)$field['options']);
            if ($optstr !== '' && ctype_digit($optstr)) {
                $max = max(1, (int)$optstr);
            } else {
                $opts = parse_ini_string($optstr);
                $max = max(1, (int)($opts['max'] ?? 100));
            }

            $val = (int)$value;
            $val = max(0, min($val, $max));
            $percent = round(($val / $max) * 100);

            // Template-Variablen
            $cs_val = $val;
            $cs_max = $max;
            $cs_percent = $percent;

            global $cs_val, $cs_max, $cs_percent;
            eval("\${$varname} = \"".$templates->get("charactersheet_view_percent")."\";");
            global $$varname;
            continue;
        }

        // Sonderfall: Spektrum
        if ($field['type'] == 'spectrum') {
            $raw = trim((string)$field['options']);

            // Optionen robust parsen (JSON bevorzugt, sonst INI, sonst Defaults)
            $cfg = json_decode($raw, true);
            if (!is_array($cfg)) {
                $cfg = @parse_ini_string($raw);
                if (!is_array($cfg)) { $cfg = []; }
            }

            $steps      = max(2, (int)($cfg['steps'] ?? 5)); // min. 2
            $leftlabel  = (string)($cfg['leftlabel']  ?? '');
            $rightlabel = (string)($cfg['rightlabel'] ?? '');

            // Radio-Dots bauen
            $spectrum_inputs = '';
            $current = (int)$value; // gespeicherter Wert (1..steps)
            for ($i = 1; $i <= $steps; $i++) {
                $checked = ($current === $i) ? ' checked="checked"' : '';
                $id = "field_{$name}_{$i}";
                $spectrum_inputs .= "<input type=\"radio\" id=\"{$id}\" name=\"field_{$name}\" value=\"{$i}\" class=\"cs-spectrum-dot\"{$checked} />";
                $spectrum_inputs .= "<label for=\"{$id}\" class=\"cs-spectrum-dot-label\" aria-label=\"{$i} von {$steps}\"></label>";
            }

            // Template rendern → $input
            eval("\$input = \"".$templates->get('charactersheet_input_spectrum')."\";");
        }

        // Sonderfall: Bild-URL (optionale Maße setzen)
        if ($field['type'] == 'image_url') {
            $cs_image_url_src = htmlspecialchars_uni((string)$value);
            if ($cs_image_src === '') { $output = ''; continue; }

            // Optionen lesen (JSON mit max/min Breite/Höhe)
            $opts  = json_decode((string)$field['options'], true) ?: [];
            $maxw  = (int)($opts['maxwidth']  ?? 0);
            $maxh  = (int)($opts['maxheight'] ?? 0);
            $minw  = (int)($opts['minwidth']  ?? 0);
            $minh  = (int)($opts['minheight'] ?? 0);

            // inline-Styles nur setzen, wenn Werte > 0
            $style_parts = [];
            if ($maxw > 0) $style_parts[] = "max-width: {$maxw}px";
            if ($maxh > 0) $style_parts[] = "max-height: {$maxh}px";
            if ($minw > 0) $style_parts[] = "min-width: {$minw}px";
            if ($minh > 0) $style_parts[] = "min-height: {$minh}px";
            $cs_image_style = implode('; ', $style_parts);

            // Alt-Text & Data-Attribute
            $cs_image_alt   = htmlspecialchars_uni($field['title'] ?: $field['var_name']);
            $cs_image_min_w = $minw;
            $cs_image_min_h = $minh;
            $cs_image_max_w = $maxw;
            $cs_image_max_h = $maxh;

            // Variablen für Template bereitstellen
            global $cs_image_src, $cs_image_alt, $cs_image_style, $cs_image_min_w, $cs_image_min_h, $cs_image_max_w, $cs_image_max_h;

            eval("\$tmp_output = \"".$templates->get('charactersheet_view_image_url')."\";");
            global $$varname;
            $$varname = $tmp_output;
        }

        // Standard-Fall: alles andere
        global $$varname;
        $$varname = htmlspecialchars_uni($value);
    }

    // Edit-Link anzeigen, wenn der eigene Steckbrief
    $edit_link = '';
    if ($mybb->user['uid'] && $mybb->user['uid'] == $uid) {
        global $edit_link;
        eval("\$edit_link = \"" . $templates->get("charactersheet_edit-link") . "\";");
    }

    foreach ($fields as $name => $field) {
        if (!cs_field_is_visible($field, $usergroups)) {
            continue;
        }
        $varname = 'charactersheet_view_' . $name;
        if (!isset($$varname)) {
            $$varname = '';
            global $$varname;
        }
    }

    // Aufbau Default View Template
    $charactersheet_view_fields = '';

    foreach ($fields as $name => $field) {

        // Rechtecheck
        $usergroups = [$mybb->user['usergroup']];
        if (!empty($mybb->user['additionalgroups'])) {
            $usergroups = array_merge($usergroups, explode(',', $mybb->user['additionalgroups']));
        }

        if (!cs_field_is_visible($field, $usergroups)) {
            continue;
        }


        // Layout-Felder
        if ($field['field_scope'] == 'layout') {
            $description = $field['description'];

            switch ($field['type']) {
                case 'layout_heading':
                    $html = "<tr><td colspan=\"2\" class=\"tcat\"><h2>{$description}</h2></td></tr>";
                    break;
                case 'layout_subheading':
                    $html = "<tr><td colspan=\"2\" class=\"tcat\"><h4>{$description}</h4></td></tr>";
                    break;
                case 'layout_info':
                    $html = "<tr><td colspan=\"2\" class=\"trow1\"><div class=\"cs-layout-info\">{$description}</div></td></tr>";
                    break;
                default:
                    $html = '';
            }

            $charactersheet_view_fields .= $html;
            continue;
        }

        // Normale Eingabefelder
        $fid = (int)$field['fid'];
        $value = $values[$fid] ?? '';

        // Sichtbarkeitsprüfung
        $can_view = cs_field_is_visible($field, $usergroups);
        // $hide_fully = (int)($field['hide_fully'] ?? 0);




        $label = htmlspecialchars_uni($field['title']);
        $output = '';

        if ($field['type'] == 'percent') {
            $optstr = trim((string)$field['options']);
                if ($optstr !== '' && ctype_digit($optstr)) {
                    $max = max(1, (int)$optstr);
                } else {
                    $opts = parse_ini_string($optstr);
                    $max = max(1, (int)($opts['max'] ?? 100));
                }

            $val = (int)$value;
            $val = max(0, min($val, $max));
            $percent = round(($val / $max) * 100);

            $cs_val = $val;
            $cs_max = $max;
            $cs_percent = $percent;
            global $cs_val, $cs_max, $cs_percent;

            eval("\$output = \"".$templates->get("charactersheet_view_percent")."\";");
        }

        elseif ($field['type'] == 'spectrum') {
            $opts = json_decode($field['options'], true);
            $steps = max(1, (int)($opts['steps'] ?? 6));
            $left = htmlspecialchars_uni($opts['leftlabel'] ?? '');
            $right = htmlspecialchars_uni($opts['rightlabel'] ?? '');
            $value = (int)$value;

            $dots = '';
            for ($i = 1; $i <= $steps; $i++) {
                $active = ($i == $value) ? ' active' : '';
                $dots .= "<span class='spectrum-dot{$active}' title='Stufe {$i}'></span>";
            }

            global $left, $right, $dots;

            eval("\$output = \"".$templates->get("charactersheet_view_spectrum")."\";");
        }       

        
        elseif ($field['type'] == 'image_url') {
            // Optionen lesen
            $opts  = json_decode((string)$field['options'], true) ?: [];
            $maxw  = (int)($opts['maxwidth']  ?? 0);
            $maxh  = (int)($opts['maxheight'] ?? 0);
            $minw  = (int)($opts['minwidth']  ?? 0);
            $minh  = (int)($opts['minheight'] ?? 0);
            $placeholder = trim((string)($opts['placeholder'] ?? ''));

            // Berechtigung über viewablegroups prüfen
            // $vg = trim((string)$field['viewablegroups']);           // z. B. "2,4,6" oder ""
           // $can_view = ($vg === '' || is_member($vg, (int)$mybb->user['uid']));

            // Quelle wählen (echte URL oder Platzhalter)
            $src = '';
            /*
            if ($can_view) {
                $src = (string)$value;
                if ($src === '') { $output = ''; continue; }
            } else {
                if ($placeholder === '') { $output = ''; continue; }
                $src = $placeholder;
            } */

            // Styles nur setzen, wenn Werte > 0
            $style_parts = [];
            if ($maxw > 0) $style_parts[] = "max-width: {$maxw}px";
            if ($maxh > 0) $style_parts[] = "max-height: {$maxh}px";
            if ($minw > 0) $style_parts[] = "min-width: {$minw}px";
            if ($minh > 0) $style_parts[] = "min-height: {$minh}px";
            $cs_image_style = implode('; ', $style_parts);

            // Template-Variablen
            $cs_image_url_src   = htmlspecialchars_uni($src);
            $cs_image_url_alt   = htmlspecialchars_uni($can_view ? ($field['title'] ?: $field['var_name']) : 'Platzhalter-Bild');
            $cs_image_url_min_w = $minw;
            $cs_image_url_min_h = $minh;
            $cs_image_url_max_w = $maxw;
            $cs_image_url_max_h = $maxh;

            global $cs_image_url_src, $cs_image_url_alt, $cs_image_style, $cs_image_url_min_w, $cs_image_url_min_h, $cs_image_url_max_w, $cs_image_url_max_h;

            eval("\$output = \"".$templates->get('charactersheet_view_image_url')."\";");
        }

            
        elseif ($field['type'] == 'image_upload') {
            // Optionen lesen
            $opts  = json_decode((string)$field['options'], true) ?: [];
            $maxw  = (int)($opts['maxwidth']  ?? 0);
            $maxh  = (int)($opts['maxheight'] ?? 0);
            $minw  = (int)($opts['minwidth']  ?? 0);
            $minh  = (int)($opts['minheight'] ?? 0);
            $placeholder = trim((string)($opts['placeholder'] ?? ''));

            // View Gruppenberechtigung
           // $vg = trim((string)$field['viewablegroups']);          
           // $can_view = ($vg === '' || is_member($vg, (int)$mybb->user['uid']));


            // Quelle bestimmen: echtes Bild oder Platzhalter
            $src = '';
            if ($can_view) {
                $src = (string)$value; // gespeicherter Pfad
                if ($src === '') { $output = ''; continue; } // nichts gespeichert → nichts anzeigen
            } else {
                if ($placeholder === '') { $output = ''; continue; } // kein Platzhalter konfiguriert
                $src = $placeholder;
            }

            // Style-String bauen (nur gesetzte Werte)
            $style_parts = [];
            if ($maxw > 0) $style_parts[] = "max-width: {$maxw}px";
            if ($maxh > 0) $style_parts[] = "max-height: {$maxh}px";
            if ($minw > 0) $style_parts[] = "min-width: {$minw}px";
            if ($minh > 0) $style_parts[] = "min-height: {$minh}px";
            $cs_image_style = implode('; ', $style_parts);

            // Template-Variablen
            $cs_image_upload_src   = htmlspecialchars_uni($src);
            $cs_image_upload_alt   = htmlspecialchars_uni($can_view ? ($field['title'] ?: $field['var_name']) : 'Platzhalter-Bild');
            $cs_image_upload_min_w = $minw;
            $cs_image_upload_min_h = $minh;
            $cs_image_upload_max_w = $maxw;
            $cs_image_upload_max_h = $maxh;

            global $cs_image_upload_src, $cs_image_upload_alt, $cs_image_style, $cs_image_upload_min_w, $cs_image_upload_min_h, $cs_image_upload_max_w, $cs_image_upload_max_h;

            eval("\$output = \"".$templates->get('charactersheet_view_image_upload')."\";");
        } 
        
        elseif ($field['type'] == 'rankscale') {
            $rank_label = htmlspecialchars_uni($value);
            global $rank_label;
            eval("\$output = \"".$templates->get("charactersheet_view_rankscale")."\";");
        } 
        
        elseif ($field['type'] == 'checkbox') {
            $selected_values = explode(',', (string)$value);
            $list_items = '';
            foreach ($selected_values as $val) {
                $val = htmlspecialchars_uni(trim($val));
                if ($val === '') continue;
                $list_items .= "<li>{$val}</li>";
            }
            $checkbox_list_items = $list_items;

            global $checkbox_list_items;
            eval("\$output = \"".$templates->get("charactersheet_view_checkbox")."\";");
        }

        else {
            $output = htmlspecialchars_uni($value);
        }

        // Inhalt leeren, aber Label zeigen
        // Sichtbarkeit final prüfen
        /* if (!$can_view) {
            if ($hide_fully == 1) {
                continue;
            } else {
                eval("\$output = \"".$templates->get('charactersheet_hidden_field')."\";");
            }
        } */




        $charactersheet_view_fields .= "<tr><td class=\"trow1\"><strong>{$label}</strong></td><td class=\"trow1\">{$output}</td></tr>";
    }



    if ($mybb->settings['charactersheet_use_default_view']) {
        eval("\$page = \"" . $templates->get("charactersheet_view_default") . "\";");
    } else {
        eval("\$page = \"" . $templates->get("charactersheet_view_custom") . "\";");
    }

    output_page($page);
}


// Eingabeseite anzeigen
elseif ($action == "edit") {

    if (!cs_user_in_allowed_groups($mybb->settings['charactersheet_editgroups'])) {
        error_no_permission();
    }


    $uid = (int)$mybb->user['uid'];

    $fields = [];
    $query = $db->simple_select("charactersheet_fields", "*", "", ["order_by" => "disporder"]);
    while ($field = $db->fetch_array($query)) {
        $fields[$field['var_name']] = $field;
    }

    $values = [];
    $query = $db->simple_select("charactersheet_values", "*", "uid = '{$uid}'");
    while ($row = $db->fetch_array($query)) {
        $values[(int)$row['fid']] = $row['value'];
    }

    $charactersheet_fields = '';
    foreach ($fields as $name => $field) {

        $fid = (int)$field['fid'];
        $value = $values[$fid] ?? '';
        $title = htmlspecialchars_uni($field['title']);
        $description = $field['description'];
        $requiredmark = ($field['required']) ? "<span style='color:red'>*</span>" : '';

      
        if ($field['type'] == 'textarea') {
            $input = "<textarea name='field_{$name}' class=\"charactersheet-input\">".htmlspecialchars_uni($value)."</textarea>";
        } 
        
        elseif ($field['type'] == 'select') {
            $options = explode("\n", trim($field['options']));
            $options_html = '';
            foreach ($options as $opt) {
                $opt = trim($opt);
                if ($opt === '') continue;
                $selected = ($opt == $value) ? ' selected="selected"' : '';
                $options_html .= "<option value=\"".htmlspecialchars_uni($opt)."\"{$selected}>".htmlspecialchars_uni($opt)."</option>";
            }
            eval("\$input = \"".$templates->get('charactersheet_input_select')."\";");
        }

        elseif ($field['type'] == 'radio') {
            $options = explode("\n", trim($field['options']));
            $options_html = '';
            foreach ($options as $opt) {
                $opt = trim($opt);
                if ($opt === '') continue;
                $checked = ($opt == $value) ? ' checked="checked"' : '';
                $options_html .= "<label><input type=\"radio\" name=\"field_{$name}\" value=\"".htmlspecialchars_uni($opt)."\"{$checked}> ".htmlspecialchars_uni($opt)."</label> ";
            }
            eval("\$input = \"".$templates->get('charactersheet_input_radio')."\";");
        }

        elseif ($field['type'] == 'checkbox') {
            $options = explode("\n", trim($field['options']));
            $selected_values = explode(',', $value);
            $options_html = '';
            foreach ($options as $opt) {
                $opt = trim($opt);
                if ($opt === '') continue;
                $checked = in_array($opt, $selected_values) ? ' checked="checked"' : '';
                $options_html .= "<label><input type=\"checkbox\" name=\"field_{$name}[]\" value=\"".htmlspecialchars_uni($opt)."\"{$checked}> ".htmlspecialchars_uni($opt)."</label> ";
            }
            eval("\$input = \"".$templates->get('charactersheet_input_checkbox')."\";");

        } 
        
        elseif ($field['type'] == 'rankscale') {
            $options = preg_split('/\r\n|\r|\n/', (string)$field['options']);
            $input = "<select name='field_{$name}' class=\"charactersheet-input\">";
            if (empty(array_filter($options))) {
                $input .= "<option value=''>-- Keine Optionen definiert --</option>";
            } else {
                foreach ($options as $opt) {
                    $opt = trim($opt);
                    if ($opt === '') { continue; }
                    $selected = ((string)$opt === (string)$value) ? " selected" : "";
                    $input .= "<option value=\"".htmlspecialchars_uni($opt)."\"{$selected}>".htmlspecialchars_uni($opt)."</option>";
                }
            }
            $input .= "</select>";
        } 
        
        elseif ($field['type'] == 'options') {
            $options = preg_split('/\r\n|\r|\n/', (string)$field['options']);
            $input = "<select name='field_{$name}' class=\"charactersheet-input\">";
            if (empty(array_filter($options))) {
                $input .= "<option value=''>-- Keine Optionen definiert --</option>";
            } else {
                foreach ($options as $opt) {
                    $opt = trim($opt);
                    if ($opt === '') { continue; }
                    $selected = ((string)$opt === (string)$value) ? " selected" : "";
                    $input .= "<option value=\"".htmlspecialchars_uni($opt)."\"{$selected}>".htmlspecialchars_uni($opt)."</option>";
                }
            }
            $input .= "</select>";      
        
        } 

        elseif ($field['type'] == 'image_url') {
            // Optionen lesen (für optionale Min/Max-Styles)
            $opts  = json_decode((string)$field['options'], true) ?: [];
            $maxw  = (int)($opts['maxwidth']  ?? 0);
            $maxh  = (int)($opts['maxheight'] ?? 0);
            $minw  = (int)($opts['minwidth']  ?? 0);
            $minh  = (int)($opts['minheight'] ?? 0);

            // Inline-Styles nur setzen, wenn Werte > 0
            $style_parts = [];
            if ($maxw > 0) $style_parts[] = "max-width: {$maxw}px";
            if ($maxh > 0) $style_parts[] = "max-height: {$maxh}px";
            if ($minw > 0) $style_parts[] = "min-width: {$minw}px";
            if ($minh > 0) $style_parts[] = "min-height: {$minh}px";
            $cs_image_style = implode('; ', $style_parts);

            // Werte für Template
            $cs_image_url_value       = htmlspecialchars_uni((string)$value);
            $cs_image_preview_src     = $cs_image_url_value;
            $cs_image_preview_display = ($cs_image_url_value !== '') ? 'block' : 'none';

            // Wichtig: $name wird im Template für die IDs benötigt
            global $cs_image_style, $cs_image_url_value, $cs_image_preview_src, $cs_image_preview_display, $name;

            eval("\$input = \"".$templates->get('charactersheet_input_image_url')."\";");
        }
        
        elseif ($field['type'] == 'image_upload') {
            $input = "<input type='file' name='field_{$name}' class=\"charactersheet-input\" />";
        } 
        
        elseif ($field['type'] == 'number') {
            $unit = htmlspecialchars_uni($field['unit_label']);
            $input = "<input type='number' name='field_{$name}' value=\"".htmlspecialchars_uni($value)."\" class=\"charactersheet-input\" /> {$unit}";        
        } 
        
        elseif ($field['type'] == 'spectrum') {
            $opts = json_decode($field['options'], true);
            $steps = max(1, (int)($opts['steps'] ?? 6));
            $left = htmlspecialchars_uni($opts['leftlabel'] ?? '');
            $right = htmlspecialchars_uni($opts['rightlabel'] ?? '');
            $selected = (int)$value;

            $input = "<div class='spectrum-edit-row'>";
            $input .= "<div class='spectrum-label spectrum-label-left'>{$left}</div>";
            $input .= "<div class='spectrum-dots'>";
            for ($i = 1; $i <= $steps; $i++) {
                $active = ($i == $selected) ? ' active' : '';
                $input .= "<label class='spectrum-dot{$active}' title='Stufe {$i}'>";
                $input .= "<input type='radio' name='field_{$name}' value='{$i}'" . ($i == $selected ? " checked" : "") . " />";
                $input .= "</label>";
            }
            $input .= "</div>";
            $input .= "<div class='spectrum-label spectrum-label-right'>{$right}</div>";
            $input .= "</div>";
        } 
        
        elseif ($field['type'] == 'percent') {
            $optstr = trim((string)$field['options']);
            if ($optstr !== '' && ctype_digit($optstr)) {
                $max = max(1, (int)$optstr);
            } else {
                $opts = parse_ini_string($optstr);
                $max = max(1, (int)($opts['max'] ?? 100));
            }
            $input = "<input type='number' name='field_{$name}' value=\"".htmlspecialchars_uni($value)."\" min=\"0\" max=\"{$max}\" class=\"charactersheet-input\" /> / {$max}";
        } 
        
        elseif ($field['type'] == 'layout_heading') {
            $description = $field['description'];
            eval("\$input = \"".$templates->get("charactersheet_layout_heading")."\";");
        }

        elseif ($field['type'] == 'layout_subheading') {
            $description = $field['description'];
            eval("\$input = \"".$templates->get("charactersheet_layout_subheading")."\";");
        }

        elseif ($field['type'] == 'layout_info') {
            $description = $field['description'];
            eval("\$input = \"".$templates->get("charactersheet_layout_info")."\";");
        } 

        else {
            $input = "<input type='text' name='field_{$name}' value=\"".htmlspecialchars_uni($value)."\" class=\"charactersheet-input\" />";
        }


        if (strpos($field['type'], 'layout_') === 0) {
            // Layouts haben ihr HTML schon direkt in $input
            $charactersheet_fields .= $input;
        } else {
        eval("\$field_html = \"".$templates->get("charactersheet_inputfield")."\";");
        $charactersheet_fields .= $field_html;
        }
    }




    eval("\$page = \"".$templates->get("charactersheet_edit")."\";");
    output_page($page);
    exit;
}

elseif ($action == "save") {
    $usergroups = [];

    $uid = (int)$mybb->user['uid'];

    // Pflichtfelder laden
        $required_fields = [];
        $query = $db->simple_select("charactersheet_fields", "*", "required = 1");
        while ($field = $db->fetch_array($query)) {
            $required_fields[] = $field;
        }

        // Fehlerarray vorbereiten
        $errors = [];

        // Prüfung: Hat Nutzer alle Pflichtfelder ausgefüllt?
        foreach ($required_fields as $field) {
            $value = null;

            if ($field['type'] == 'checkbox') {
                // Checkboxen kommen als Array
                $value_array = $mybb->get_input("field_{$field['var_name']}", MyBB::INPUT_ARRAY);
                if (empty($value_array)) {
                    $errors[] = "Das Feld „{$field['title']}“ ist ein Pflichtfeld und darf nicht leer sein.";
                }
                continue;
            }

            $value = trim($mybb->get_input("field_{$field['var_name']}"));
            if ($value === '') {
                $errors[] = "Das Feld „{$field['title']}“ ist ein Pflichtfeld und darf nicht leer sein.";
            }
        }


        // Wenn Fehler: Ausgabe + Abbruch
        if (!empty($errors)) {
            $errorlist = "<ul>";
            foreach ($errors as $e) {
                $errorlist .= "<li>{$e}</li>";
            }
            $errorlist .= "</ul>";
            error("Dein Steckbrief konnte nicht gespeichert werden:<br />{$errorlist}");
        }


    $fields = [];
    $query = $db->simple_select("charactersheet_fields", "*");
    while ($field = $db->fetch_array($query)) {
        $fields[$field['var_name']] = $field;
    }

    foreach ($fields as $name => $field) {

    $fid = (int)$field['fid'];

    // Bild-Upload separat behandeln
    if ($field['type'] == 'image_upload') {
        if (isset($_FILES["field_{$name}"]) && $_FILES["field_{$name}"]['error'] == 0) {
            $upload = $_FILES["field_{$name}"];
            $ext = strtolower(ltrim(pathinfo($upload['name'], PATHINFO_EXTENSION), '.')); 
            $options = json_decode($field['options'], true);

            $allowed_raw = (string)($options['allowedtypes'] ?? 'jpg,png,gif');
            $allowed = preg_split('/[,\s;|]+/', $allowed_raw, -1, PREG_SPLIT_NO_EMPTY);
            $allowed = array_map(function($x){
                return strtolower(ltrim(trim($x), '.')); // z.B. ".PNG" -> "png"
            }, $allowed);

            if (!in_array(strtolower($ext), $allowed)) {
                error("Ungültiger Dateityp.");
            }

            if ($upload['size'] > ((int)($options['maxfilesize'] ?? 2048)) * 1024) {
                error("Datei zu groß.");
            }

            $filename = "uploads/charactersheet_{$mybb->user['uid']}_{$name}." . $ext;
            move_uploaded_file($upload['tmp_name'], $filename);

            $db->delete_query("charactersheet_values", "uid='{$uid}' AND fid='{$fid}'");
            $db->insert_query("charactersheet_values", [
                'uid' => $uid,
                'fid' => $fid,
                'value' => $db->escape_string($filename)
            ]);
        }
        continue;
    }

    // Checkbox-Handling
    if ($field['type'] == 'checkbox') {
        $value_array = $mybb->get_input("field_{$name}", MyBB::INPUT_ARRAY);
        $value = implode(',', array_map('trim', (array)$value_array));
    }
    // Alles andere
    else {
        $value = trim($mybb->get_input("field_{$name}"));
    }

    // In DB speichern
    $db->delete_query("charactersheet_values", "uid='{$uid}' AND fid='{$fid}'");
    $db->insert_query("charactersheet_values", [
        'uid' => $uid,
        'fid' => $fid,
        'value' => $db->escape_string($value)
    ]);
}


    redirect("charactersheet.php?action=view&uid={$uid}", "Dein Steckbrief wurde gespeichert.");
}

else {
    error("Du hast keine Rechte, diese Seite anzusehen.");
}

