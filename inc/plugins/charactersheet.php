<?php
/**
 * Plugin Name: Charactersheets Ex
 * Description: Strukturiere Extra Charakterübersicht mit flexiblen Eingabefeldern, übersichtlichem Layout und umfangreicher Konfiguration.
 * Author: White_Rabbit
 * Version: 1.0
 */

if (!defined("IN_MYBB")) {
    die("Direct access not allowed.");
}

$plugins->add_hook('admin_config_menu', 'charactersheet_admin_menu');
$plugins->add_hook('admin_config_action_handler', 'charactersheet_admin_action');

// Registrierung des Plugins
function charactersheet_info() {
    return [
        "name" => "Charactersheets Ex",
        "description" => "Strukturiere Extra Charakterübersicht mit flexiblen Eingabefeldern, übersichtlichem Layout und umfangreicher Konfiguration.",
        "website" => "https://github.com/whitera-bit/",
        "author" => "White_Rabbit",
        "authorsite" => "https://github.com/whitera-bit/",
        "version" => "1.0",
        "compatibility" => "18*"
    ];
}

function charactersheet_is_installed()
{
    global $db;
    return $db->table_exists("charactersheet_fields");
}


function charactersheet_install() {
    global $db;

    if (!$db->table_exists("charactersheet_fields")) {
        $db->query("CREATE TABLE ".TABLE_PREFIX."charactersheet_fields (
            fid INT AUTO_INCREMENT PRIMARY KEY,
            field_scope ENUM('user','layout') NOT NULL DEFAULT 'user',
            var_name VARCHAR(500) NOT NULL,
            title VARCHAR(500) NOT NULL,
            description TEXT,
            viewablegroups TEXT,
            editablegroups TEXT,
            options TEXT,
            disporder INT DEFAULT 0,
            required TINYINT(1) NOT NULL DEFAULT 0,
            unit_label VARCHAR(100) NOT NULL DEFAULT '',
            type VARCHAR(250) NOT NULL
        )");
    }

    if (!$db->table_exists("charactersheet_values")) {
        $db->query("CREATE TABLE ".TABLE_PREFIX."charactersheet_values (
            vid INT AUTO_INCREMENT PRIMARY KEY,
            uid INT,
            fid INT,
            value TEXT
        )");
    }

    // Templates & CSS einfügen

    require_once MYBB_ADMIN_DIR."inc/functions_themes.php";
    require MYBB_ROOT."/inc/adminfunctions_templates.php";

    // Template Gruppe erstellen
    $template_group = array(
        'prefix' => $db->escape_string("charactersheet"),
        'title' => $db->escape_string("Charaktersheet"),
        'isdefault' => 0
    );

    $db->insert_query('templategroups', $template_group);
 
    // ## Templates Ausgabe
    $templates = [
    'charactersheet_view_custom' => '

        <html>
        <head>
        <title>{$settings[\'bbname\']} - Charaktersheet bearbeiten</title>
        {$headerinclude}
        </head>
        <body>
        {$header}
        <table border="0" cellspacing="{$theme[\'borderwidth\']}" cellpadding="{$theme[\'tablespace\']}" class="tborder">
        <tr>
        <td class="thead"><strong>Charaktersheet</strong></td>
        </tr>
        <tr>
        <td class="trow1" align="center">
        <!-- ## Füge hier deine eigenen Variablen ein im Format {$charactersheet_view_feldname} - Die Feldnamen findest du im ACP Konfiguration > Charactersheet bearbeiten beim Anlegen der Felder --->
        

        {$edit_link}
        </td>
        </tr>
        </table>
        {$footer}
        </body>
        </html>    
    ',


    'charactersheet_edit' => '
    
    <html>
    <head>
    <title>{$settings[\'bbname\']} - Charaktersheet bearbeiten</title>
    {$headerinclude}
    </head>
    <body>
    {$header}

    <form action="charactersheet.php?action=save" method="post" enctype="multipart/form-data">
        <table class="tborder charactersheet-table">
            <tr>
                <td class="thead" colspan="2"><strong>Charaktersheet bearbeiten</strong></td>
            </tr>
            {$charactersheet_fields}
            <tr>
                <td class="trow1" colspan="2" align="center">
                    <input type="submit" value="Speichern" />
                </td>
            </tr>
        </table>
    </form>

    {$footer}
    </body>
    </html>

    
    ',

    'charactersheet_view_default' => '
    
    <html>
        <head>
        <title>{$settings[\'bbname\']} - Charaktersheet bearbeiten</title>
        {$headerinclude}
        </head>
        <body>
        {$header}
            <table class="tborder charactersheet-table" width="100%" cellpadding="6" cellspacing="1">
            <thead>
                <tr>
                    <th class="tcat">Feld</th>
                    <th class="tcat">Wert</th>
                </tr>
            </thead>
            <tbody>
                {$charactersheet_view_fields}
            </tbody>
        </table>

        {$edit_link}
            
            {$footer}
        </body>
    </html>
        
    
    ',


    'charactersheet_edit-link' => '
    
    <div class="charactersheet-editlink">
        <a href="charactersheet.php?action=edit">Steckbrief bearbeiten</a>
    </div>

    
    ',


    'charactersheet_inputfield' => '
    
    <tr>
        <td class="trow1 label">
            <b>{$title} {$requiredmark}</b>
            <br><small>{$description}</small>
        </td>
        <td class="trow1 input">
            {$input}
        </td>
    </tr>
    
    
    ',


    'charactersheet_view_percent' => '
    
    <div class="progressbar" title="{$cs_val} / {$cs_max}">
        <div class="fill" style="width: {$cs_percent}%"></div>
    </div>

    
    ',

    'charactersheet_view_spectrum' => '
    
    <div class="spectrum-field">
        <span class="spectrum-label-left">{$left}</span>
        <span class="spectrum-dots" title="Stufe {$left} - {$right}">
            {$dots}
        </span>
        <span class="spectrum-label-right">{$right}</span>
    </div>


    ',

    'charactersheet_view_image_url' => '
    
    <div class="cs-image cs-image-url">
        <img src="{$cs_image_url_src}" alt="{$cs_image_url_alt}" class="cs-image-tag" style="{$cs_image_style}" data-min-w="{$cs_image_url_min_w}" data-min-h="{$cs_image_url_min_h}" data-max-w="{$cs_image_url_max_w}" data-max-h="{$cs_image_url_max_h}">
    </div>
    

    ',

    'charactersheet_view_image_upload' => '
    
    <div class="cs-image">
        <img src="{$cs_image_upload_src}" alt="{$cs_image_upload_alt}" class="cs-image-tag" style="{$cs_image_style}" data-min-w="{$cs_image_upload_min_w}" data-min-h="{$cs_image_upload_min_h}" data-max-w="{$cs_image_upload_max_w}" data-max-h="{$cs_image_upload_max_h}">
    </div>
    
    
    ',
    
    'charactersheet_view_rankscale' => '
    
    <div class="charactersheet-rank">
        <span class="rank-label">{$rank_label}</span>
    </div>
    
    
    ',

    
    'charactersheet_view_checkbox' => '
    
   <ul class="charactersheet-checkboxes">
        {$checkbox_list_items}
    </ul>
    
    
    ',

        'charactersheet_layout_heading' => '
    
    <tr>
        <td class="trow1 label" colspan="2">
            <h4 class="cs-layout-heading">{$description}</h4>
        </td>
    </tr>
    
    
    ',

    'charactersheet_layout_subheading' => '
    
    <tr>
        <td class="trow1 label" colspan="2">
            <h2 class="cs-layout-subheading">{$description}</h2>
        </td>
    </tr>
    
    
    ',

    'charactersheet_layout_info' => '
    
    <tr>
        <td class="trow1 label" colspan="2">
            <div class="cs-layout-info">{$description}</div>
        </td>
    </tr>
    
    ',

    'charactersheet_input_checkbox' => '
    
    <div class="charactersheet-checkbox-group">
        {$options_html}
    </div>
    
    ',

    
    'charactersheet_input_radio' => '
    
    <div class="charactersheet-radio-group">
        {$options_html}
    </div>
    
    ',

    
    'charactersheet_input_select' => '
    
    <select name="field_{$name}" class="charactersheet-input">
        {$options_html}
    </select>
    
    ',
    
    
    'charactersheet_input_image_url' => '
    
    <div class="cs-imageurl-input">
        <input type="url" name="field_{$name}" id="field_{$name}" value="{$cs_image_url_value}" class="charactersheet-input" placeholder="https://…"/>
    </div>
    
    ',

    'charactersheet_input_spectrum' => '
    
   <div class="cs-spectrum-row">
        <span class="cs-spectrum-label cs-spectrum-label--left">{$leftlabel}</span>

        <div class="cs-spectrum-dots">
            {$spectrum_inputs}
        </div>

        <span class="cs-spectrum-label cs-spectrum-label--right">{$rightlabel}</span>
    </div>
    
    '

];


    foreach ($templates as $title => $template) {
        $insert_array = [
            'title'     => $title,
            'template'  => $db->escape_string($template),
            'sid'       => -2,
            'version'   => '',
            'dateline'  => TIME_NOW
        ];
        $db->insert_query('templates', $insert_array);
    }

    $css = array(
        'name'  => 'charactersheet.css',
        'tid'   => 1,
        'attachedto' => '',
        "stylesheet" =>	'
        /* ----------------------------
        1. Grundstruktur Edit-Seite
        ---------------------------- */
            .charactersheet-table {
                width: 100%;
                border-collapse: collapse;
            }

            .charactersheet-table td {
                vertical-align: top;
                padding: 8px;
            }

            .charactersheet-table td.label {
                width: 33%;
            }

            .charactersheet-table td.input {
                width: 67%;
            }

            .charactersheet-table textarea,
            .charactersheet-table input[type="text"],
            .charactersheet-table input[type="url"],
            .charactersheet-table input[type="file"],
            .charactersheet-table input[type="number"],
            .charactersheet-table select {
                width: 100%;
                box-sizing: border-box;
                max-width: 100%;
            }

            .charactersheet-table input[type="number"] {
                width: 40%;
            }


            /* ----------------------------
            2. Progressbar (Prozent-Felder)
            ---------------------------- */
            .progressbar {
                background: #eee;
                border: 1px solid #ccc;
                width: 100%;
                height: 1.2em;
                position: relative;
                border-radius: 4px;
                overflow: hidden;
            }

            .progressbar .fill {
                background: #3c9;
                height: 100%;
                transition: width 0.4s ease;
            }


            /* ----------------------------
            3. Spectrum (Spektrum-Felder)
            ---------------------------- */
            .spectrum-field {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                margin: 0.5em 0;
            }

            .spectrum-label-left,
            .spectrum-label-right {
                font-weight: bold;
                font-size: 0.9em;
            }

            .spectrum-dots {
                display: flex;
                gap: 6px;
                flex-grow: 1;
                justify-content: center;
            }

            .spectrum-edit-row {
                display: flex;
                justify-content: space-between;
                gap: 20px;
            }

            .spectrum-dot {
                display: inline-block;
                width: 12px;
                height: 12px;
                border-radius: 50%;
                background-color: #ccc; /* inaktive Punkte */
            }

            .spectrum-dot.active {
                background-color: #333; /* aktiver Punkt */
            }


            /* ----------------------------
            4. Layout-Feldtypen
            ---------------------------- */
            .cs-layout-heading {
                font-size: 1.4em;
                margin: 1em 0 0.5em;
                border-bottom: 1px solid #ccc;
            }

            .cs-layout-subheading {
                font-size: 1.1em;
                margin: 1em 0 0.5em;
            }

            .cs-layout-info {
                padding: 0.5em 1em;
            }
      
        ',
        'cachefile'     => $db->escape_string(str_replace('/', '', 'charactersheet.css')),
        'lastmodified'  => time()
        ); 

        $sid = $db->insert_query("themestylesheets", $css);
        $db->update_query("themestylesheets", array("cachefile" => "css.php?stylesheet=".$sid), "sid = '".$sid."'", 1);

        $tids = $db->simple_select("themes", "tid");
        while($theme = $db->fetch_array($tids)) {
            update_theme_stylesheet_list($theme['tid']);
        }


    // Settings-Gruppe erstellen
    $setting_group = [
        'name' => 'charactersheet_settings',
        'title' => 'Charactersheet Ex Einstellungen',
        'description' => 'Globale Einstellungen für das Charactersheet-Plugin. (Einzelne Charactersheet Felder werden unter <b>Konfiguration > Charakterbogenverwaltung</b> eingestellt.',
        'disporder' => 1,
        'isdefault' => 0
    ];
    $gid = $db->insert_query("settinggroups", $setting_group);

    // Einstellungen definieren
    $settings = [
        'charactersheet_viewgroups' => [
            'title' => 'Kann den Charakterbogen grundsätzlich sehen ansehen.',
            'description' => 'Wähle Benutzergruppen, die die Ausgabeseite sehen dürfen. Rechte für einzelne Felder kannst du beim Erstellen der Felder einstellen.',
            'optionscode' => 'groupselect',
            'value' => '-1', // -1 = alle
            'disporder' => 1,
            'gid' => $gid
        ],
        'charactersheet_editgroups' => [
            'title' => 'Kann den Charakterbogen grundsätzlich ausfüllen.',
            'description' => 'Wähle Benutzergruppen, die die Eingabeseite nutzen dürfen. Rechte für einzelne Felder kannst du beim Erstellen der Felder einstellen.',
            'optionscode' => 'groupselect',
            'value' => '2,4', // z. B. Admins + Registrierte
            'disporder' => 2,
            'gid' => $gid
        ],

        'charactersheet_use_default_view' => [
            'title' => 'Default View verwenden',
            'description' => 'Wenn aktiviert, wird der Charakterbogen automatisch aus den erstellten Feldern zusammengebaut. Wenn deaktiviert, kannst du das Template charactersheet_view_custom frei gestalten.
            Eine genaue Anleitung findest du unter <b>Einstellungen > Charakterbogenverwaltung > Anleitung & Variablen.',
            'optionscode' => 'yesno',
            'value' => 1, // Standard: ja
            'disporder' => 3,
            'gid' => $gid
        ]
        /*,

        'charactersheet_hidden_content' => [
            'title' => 'Text für versteckte Felder',
            'description' => 'Dieser Text wird in der View angezeigt, wenn ein Feld zwar nicht sichtbar ist, aber nicht vollständig verborgen werden soll. Wenn das Feld gar nicht sichtbar sein soll, wähle bei den Feldeinstellungen die entsprechende Einstellung. (Bei Custom View kannst du das Feld auch einfach ganz weglassen.)',
            'optionscode' => 'text',
            'value' => 'Nicht sichtbar.',
            'disporder' => 4,
            'gid' => $gid
        ] */

    ];

    // Einfügen
    foreach($settings as $name => $setting) {
        $setting['name'] = $name;
        $db->insert_query('settings', $setting);
    }

rebuild_settings();
}


// Plugin deinstallieren
function charactersheet_uninstall()
{
    global $db;


    if ($db->table_exists('charactersheet_fields'))  $db->drop_table('charactersheet_fields');
    if ($db->table_exists('charactersheet_values'))  $db->drop_table('charactersheet_values');


    // 2) Settings + Setting-Gruppe löschen
    $db->delete_query('settings', "name LIKE 'charactersheet_%'");  
    $db->delete_query('settinggroups', "name = 'charactersheet_settings'");

    rebuild_settings();

    // 3) Templates löschen
    $db->delete_query('templates', "title LIKE 'charactersheet_%'");

    // 4) Template-Gruppe löschen
    $db->delete_query('templategroups', "prefix='charactersheet'");

    // 5) Stylesheet entfernen
    require_once MYBB_ADMIN_DIR."inc/functions.php";
    $db->delete_query("themestylesheets", "name = 'charactersheet.css'");
    $query = $db->simple_select("themes", "tid");
    while ($theme = $db->fetch_array($query)) {
        if (function_exists("update_theme_stylesheet_list")) {
            update_theme_stylesheet_list($theme['tid']);
        }
    }

}




// **Deaktivierungsfunktion**
function charactersheet_deactivate() {
    global $db;
    require_once MYBB_ADMIN_DIR."inc/functions_themes.php";

    // ERGÄNZEN!
}


function charactersheet_admin_menu(&$sub_menu)
{
    $sub_menu[] = [
        'id' => 'charactersheet',
        'title' => 'Charakterbogenverwaltung',
        'link' => 'index.php?module=config-charactersheet'
    ];
}

function charactersheet_admin_action(&$actions)
{
    $actions['charactersheet'] = [
        'active' => 'charactersheet',
        'file' => 'charactersheet.php'
    ];
}





