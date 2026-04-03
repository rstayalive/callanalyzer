FreePBX call analyzer.
Requirements: FreePBX 16/17 with PJSIP

Just enter the date and phone number to analyze all calls. 
A full log file is attached to each call for manual analysis.

Simple setup: Go to Admin > Module Admin > Upload Modules to install the module.

Or upload files to /var/www/html/admin/modules/callanalyzer

chown asterisk:asterisk /var/www/html/admin/modules/callanalyzer

fwconsole ma install callanalyzer

fwconsole reload 
![Y7MspnvaxW](https://github.com/user-attachments/assets/18d82d49-d6b9-4649-a769-2c91f9136e25)
