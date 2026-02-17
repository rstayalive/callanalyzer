FreePBX call analyzer.
Just enter the date and phone number to analyze all calls. 
A full log file is attached to each call for manual analysis.

Simple setup: Go to Admin > Module Admin > Upload Modules to install the module.

Or upload ZIP file to /var/www/html/admin/modules/callanalyzer

chown asterisk:asterisk /var/www/html/admin/modules/callanalyzer

wconsole ma install callanalyzer

fwconsole reload 
<img width="1946" height="793" alt="Screenshot 2026-02-17 110422" src="https://github.com/user-attachments/assets/c1c557bb-552e-49ee-bfe8-ffe4501cbf8f" />
