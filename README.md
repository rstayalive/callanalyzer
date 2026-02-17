FreePBX call analyzer.
Just enter the date and phone number to analyze all calls. 
A full log file is attached to each call for manual analysis.

Simple setup: Go to Admin > Module Admin > Upload Modules to install the module.
Or upload to /var/www/html/admin/modules/callanalyzer
chown asterisk:asterisk /var/www/html/admin/modules/callanalyzer
fwconsole ma install callanalyzer
fwconsole reload
