<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Domain Lookup</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/milligram/1.4.1/milligram.min.css">
</head>
<body>
    <div class="container">
      <h1>Domain Lookup</h1>
      <div class="row">
        <div class="column"><input type="text" id="domainInput" placeholder="Enter Domain Name" autocapitalize="none"></div>
        <div class="column"><img id="captchaImg" src="captcha.php" onclick="this.src='captcha.php?'+Math.random();"></div>
        <div class="column"><input type="text" id="captchaInput" placeholder="Enter Captcha" autocapitalize="none"></div>
        <div class="column"><button class="button" id="whoisButton">WHOIS</button> <button class="button button-outline" id="rdapButton">RDAP</button></div>
      </div>

      <div class="row">
        <div class="column"><pre><code><div id="result"></div></code></pre></div>
      </div>

    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('whoisButton').addEventListener('click', function() {
                var domain = document.getElementById('domainInput').value;
                var captcha = document.getElementById('captchaInput').value;

                fetch('check.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'domain=' + encodeURIComponent(domain) + '&captcha=' + encodeURIComponent(captcha) + '&type=whois'
                })
                .then(response => response.text())
                .then(data => {
                    document.getElementById('result').innerText = data;
                    // Reload captcha after a successful response
                    document.getElementById('captchaImg').src = 'captcha.php?' + Math.random();
                })
                .catch(error => console.error('Error:', error));
            });
            
            document.getElementById('rdapButton').addEventListener('click', function() {
                var domain = document.getElementById('domainInput').value;
                var captcha = document.getElementById('captchaInput').value;

                fetch('check.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'domain=' + encodeURIComponent(domain) + '&captcha=' + encodeURIComponent(captcha) + '&type=rdap'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        console.error('Error:', data.error);
                        document.getElementById('result').innerText = 'Error: ' + data.error;
                    } else {
                        // Parse and display RDAP data
                        let output = parseRdapResponse(data);
                        document.getElementById('result').innerText = output;
                    }
                    // Reload captcha
                    document.getElementById('captchaImg').src = 'captcha.php?' + Math.random();
                })
                .catch(error => console.error('Error:', error));
            });
        });
        
        function parseRdapResponse(data) {
            let output = '';

            // Domain Name and Status
            output += 'Domain Name: ' + (data.ldhName || 'N/A') + '\n';
            output += 'Status: ' + (data.status ? data.status.join(', ') : 'N/A') + '\n\n';

            // Parsing entities for specific roles like registrar and registrant
            if (data.entities && data.entities.length > 0) {
                data.entities.forEach(entity => {
                    if (entity.roles) {
                        output += entity.roles.join(', ').toUpperCase() + ' Contact:\n';
                        if (entity.vcardArray && entity.vcardArray.length > 1) {
                            output += parseVcard(entity.vcardArray[1]);
                        }
                        if (entity.roles.includes('registrar') && entity.publicIds) {
                            output += '   IANA ID: ' + entity.publicIds.map(id => id.identifier).join(', ') + '\n';
                        }
                        if (entity.roles.includes('abuse') && entity.vcardArray) {
                            const emailEntry = entity.vcardArray[1].find(entry => entry[0] === 'email');
                            if (emailEntry) {
                                output += '   Abuse Email: ' + emailEntry[3] + '\n';
                            }
                        }
                        output += '\n';
                    }
                });
            }
        
            // Nameservers
            if (data.nameservers && data.nameservers.length > 0) {
                output += 'Nameservers:\n';
                data.nameservers.forEach(ns => {
                    output += ' - ' + ns.ldhName + '\n';
                });
                output += '\n';
            }

            // SecureDNS Details
            if (data.secureDNS) {
                output += 'SecureDNS:\n';
                output += ' - Delegation Signed: ' + (data.secureDNS.delegationSigned ? 'Yes' : 'No') + '\n';
                output += ' - Zone Signed: ' + (data.secureDNS.zoneSigned ? 'Yes' : 'No') + '\n\n';
            }

            // Events (like registration, expiration dates)
            if (data.events && data.events.length > 0) {
                output += 'Events:\n';
                data.events.forEach(event => {
                    output += ' - ' + event.eventAction + ': ' + new Date(event.eventDate).toLocaleString() + '\n';
                });
                output += '\n';
            }

            // Domain Status and Notices
            if (data.notices && data.notices.length > 0) {
                output += 'Notices:\n';
                data.notices.forEach(notice => {
                    output += ' - ' + (notice.title || 'Notice') + ': ' + notice.description.join(' ') + '\n';
                });
            }

            return output;
        }

        function parseVcard(vcard) {
            let vcardOutput = '';
            vcard.forEach(entry => {
                switch (entry[0]) {
                    case 'fn':
                        vcardOutput += '   Name: ' + entry[3] + '\n';
                        break;
                    case 'adr':
                        if (Array.isArray(entry[3]) && entry[3].length > 0) {
                            // Assuming that the address parts are in the correct order
                            const addressParts = entry[3];
                            vcardOutput += '   Address: ' + addressParts.join(', ') + '\n';
                        }
                        break;
                    case 'email':
                        vcardOutput += '   Email: ' + entry[3] + '\n';
                        break;
                    case 'tel':
                        vcardOutput += '   Phone: ' + entry[3] + '\n';
                        break;
                }
            });
            return vcardOutput;
        }
    </script>

</body>
</html>