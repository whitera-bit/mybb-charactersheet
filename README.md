# Charactersheet Plugin for MyBB / Steckbrief-Plugin für MyBB

⚠️ **Alpha Version – for testing purposes only. Use at your own risk.**  
⚠️ **Alpha-Version – nur zu Testzwecken. Nutzung auf eigene Gefahr.**

---

## 🧩 What is this? / Was ist das?

This is a custom plugin for MyBB that adds editable character sheets to user profiles.  
Users and admins can configure and display structured character data, including text fields, images, labels and more.

Dies ist ein benutzerdefiniertes Plugin für MyBB, das editierbare Steckbriefe in Nutzerprofilen hinzufügt.  
Nutzer:innen und Admins können strukturierte Felder anzeigen und bearbeiten, inklusive Textfeldern, Bildern, Labels und mehr.

---

## 🚀 Features / Funktionen

- Fully customizable character sheet fields (ACP interface)
- Field types: text, textarea, checkbox, dropdown, spectrum, rankscale, image (upload/url), headings
- Group-based visibility and edit rights
- Frontend editing via UserCP
- Optional template-based display customization

- Vollständig konfigurierbare Steckbrief-Felder (im Admin-Panel)
- Feldtypen: Text, Textbereich, Checkbox, Dropdown, Spektrum, Rangskala, Bild (Upload/URL), Überschriften
- Sichtbarkeit und Bearbeitbarkeit über Benutzergruppen steuerbar
- Bearbeitung im User-CP
- Ausgabe über Templates anpassbar

---

## 📦 Installation

1. Copy all plugin files into the corresponding directories of your MyBB installation.
   - `charactersheet.php`
   - `inc/plugins/charactersheet.php`
   - `admin/modules/config/charactersheet.php`

3. Activate the plugin in the Admin CP under  
   **Configuration → Plugins → Charactersheet**

1. Kopiere alle Dateien in die entsprechenden Ordner deiner MyBB-Installation:
   - `charactersheet.php`
   - `inc/plugins/charactersheet.php`
   - `admin/modules/config/charactersheet.php`

2. Aktiviere das Plugin im Admin-CP unter  
   **Konfiguration → Plugins → Charactersheet Ex**

---

## 🧪 Current Limitations / Aktuelle Einschränkungen

- `hide_fully` support is disabled in this version (planned for next release)
- Alpha version is not feature-frozen – changes likely

- `hide_fully` ist in dieser Version deaktiviert (geplant für nächsten Release)
- Alpha-Version – Änderungen und Umbauten wahrscheinlich

---

## 🗃️ Database Tables

This plugin creates the following tables:

- `mybb_charactersheet_fields` – stores the defined fields
- `mybb_charactersheet_data` – stores the actual character data per user

---

## 🧩 Templates

This Plugin creates the following templates & css:

### View
- `charactersheet_view_default`
- `charactersheet_view_custom`
- `charactersheet_view_percent`
- `charactersheet_view_spectrum`
- `charactersheet_view_image_url`
- `charactersheet_view_image_upload`
- `charactersheet_view_rankscale`
- `charactersheet_view_checkbox`
- `charactersheet_view_hidden_field` *(wenn du sie später ergänzt)*

### Edit
- `charactersheet_edit`
- `charactersheet_edit-link`
- `charactersheet_inputfield`
- `charactersheet_input_checkbox`
- `charactersheet_input_radio`
- `charactersheet_input_select`
- `charactersheet_input_image_url`
- `charactersheet_input_spectrum`

### Layout
- `charactersheet_layout_heading`
- `charactersheet_layout_subheading`
- `charactersheet_layout_info`

## 🎨 Stylesheet
- `charactersheet.css`

## ⚙️ Globale Plugin-Settings
- `charactersheet_viewgroups` → Who can see the charactersheet?
- `charactersheet_editgroups` → Who can edit a charactersheet?
- `charactersheet_use_default_view` → Default or Custom View (`charactersheet_view_custom`)


## 📥 Feedback & Testing

This plugin is currently under closed testing. Please do not download and use until 
you have been asked to. Likewise, please note that since this is not the final version 
of the pre-release Plugin, bug reports and suggestions for changes might be ignored.

Dieses Plugin ist derzeit in der Closed Testing Phase. Bitte lade es nicht herunter und 
nutze es nicht, wenn du nicht dazu aufgefordert wurdest. Bitte beachte außerdem, dass Bug
Reports und Änderungsvorschläge für diese Pre-Release Version ignoriert werden könnten.

---

## 📄 License

[MIT License](LICENSE)
