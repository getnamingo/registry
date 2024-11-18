<?php
session_start();
$c = require_once 'config.php';

$c['registry_name'] = isset($c['registry_name']) ? $c['registry_name'] : 'Domain Registry LLC';
$c['registry_url'] = isset($c['registry_url']) ? $c['registry_url'] : 'https://example.com';
$c['branding'] = isset($c['branding']) ? $c['branding'] : false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Domain Lookup</title>
    <style>
    /* Resetting and base styles */
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    /* Improved font settings using system fonts */
    body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        background-color: #fff;
        color: #333;
        line-height: 1.6;
        padding: 20px;
        font-size: 16px;
    }

    .container {
        max-width: 960px;
        margin: 0 auto;
        text-align: center;
    }

    header h1 {
        font-size: 2.2rem;
        font-weight: 700;
        margin-bottom: 20px;
        color: #222;
    }

    .input-container {
        display: flex;
        flex-direction: column;
        align-items: center;
        margin-bottom: 20px;
    }

    /* Input field styling with improved typography */
    input[type="text"] {
        width: 100%;
        padding: 12px;
        font-size: 1rem;
        font-family: inherit;
        border: 2px solid #ccc;
        border-radius: 5px;
        margin-bottom: 10px;
        transition: border 0.3s ease;
    }

    input[type="text"]:focus {
        border-color: #007BFF;
        outline: none;
    }
    
    /* General link styles */
    a {
        color: #007BFF;
        text-decoration: none;
        transition: color 0.3s ease, text-decoration 0.3s ease;
    }

    /* Hover and focus states for links */
    a:hover,
    a:focus {
        color: #0056b3;
        text-decoration: underline;
    }

    /* CAPTCHA container styling */
    .captcha-container {
        display: flex;
        align-items: center;
        margin-bottom: 20px;
    }

    #captchaImg {
        width: 150px;
        height: 50px;
        margin-right: 10px;
        border: 2px solid #ccc;
        border-radius: 5px;
    }

    #captchaInput {
        padding: 10px;
        font-size: 1rem;
        font-family: inherit;
        border: 2px solid #ccc;
        border-radius: 5px;
        flex-grow: 1;
        transition: border 0.3s ease;
    }

    #captchaInput:focus {
        border-color: #007BFF;
        outline: none;
    }

    /* Button styling */
    .buttons {
        display: flex;
        gap: 10px;
    }

    .buttons button {
        padding: 12px 24px;
        font-size: 1.1rem;
        font-family: inherit;
        color: #fff;
        background-color: #007BFF;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        transition: background-color 0.3s ease, transform 0.2s ease;
    }

    .buttons button:hover {
        background-color: #0056b3;
        transform: scale(1.05);
    }

    /* Result display area */
    pre {
        white-space: pre-wrap;
        white-space: -moz-pre-wrap;
        white-space: -pre-wrap;
        white-space: -o-pre-wrap;
        word-wrap: break-word;
        overflow-y: visible;
        height: auto;
        max-height: none;
        text-align: left;
        background-color: #f0f0f0;
        border: 2px solid #ccc;
        color: #333!important;
        padding: 20px;
        border-radius: 5px;
        width: 100%;
        font-size: 1rem;
    }

    /* Footer styling */
    footer {
        margin-top: 40px;
        font-size: 0.9rem;
        color: #777;
    }
    
    #bottom {
        display: none;
    }

    @media (max-width: 600px) {
        .captcha-container {
            flex-direction: column;
            align-items: center;
        }

        #captchaImg {
            margin-right: 0;
            margin-bottom: 10px;
        }

        #captchaInput {
            width: 100%;
        }

        .buttons {
            width: 100%;
            display: flex;
            justify-content: space-between;
        }

        .buttons button {
            width: 48%;
        }
        
        pre {
            font-size: 0.85rem;
        }
    }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Domain Lookup</h1>
        </header>
        <main>
            <div class="input-container">
                <input type="text" id="domainInput" placeholder="Enter domain name" autocapitalize="none">
                <?php if ($c['ignore_captcha'] === false) { ?>
                <div class="captcha-container">
                    <img alt="Captcha" id="captchaImg" src="captcha.php" onclick="this.src='captcha.php?'+Math.random();">
                    <input type="text" id="captchaInput" placeholder="Enter CAPTCHA" autocapitalize="none">
                </div>
                <?php } ?>
                <div class="buttons">
                    <button id="whoisButton">WHOIS</button>
                    <button id="rdapButton">RDAP</button>
                </div>
            </div>
            <div id="bottom">
                <pre id="result"></pre>
            </div>
        </main>
        <footer>
            <p>&copy; <?php echo date('Y'); ?> <strong><a href="<?php echo $c['registry_url']; ?>" target="_blank"><?php echo $c['registry_name']; ?></a></strong> <?php if ($c['branding'] === true) { ?> &middot; Powered by <a href="https://namingo.org" target="_blank">Namingo</a><?php } ?></p>
        </footer>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const domainInput = document.getElementById('domainInput');
            const errorMessage = document.getElementById('errorMessage');

            function validateInput() {
                const domain = domainInput.value.trim();
                const resultContainer = document.getElementById('result');
                const bottomContainer = document.getElementById('bottom');

                if (!domain) {
                    resultContainer.innerHTML = '<span style="color: #d9534f;">Please enter a valid domain name.</span>';
                    bottomContainer.style.display = 'block'; // Ensure the container is visible
                    domainInput.focus(); // Focus back on the input field
                    return false;
                }

                resultContainer.innerText = ''; // Clear previous messages
                bottomContainer.style.display = 'none'; // Hide the container
                return true;
            }

            document.getElementById('domainInput').addEventListener('keypress', function(event) {
                // Check if the key pressed is 'Enter'
                if (event.key === 'Enter') {
                    // Prevent the default action to avoid form submission or any other default behavior
                    event.preventDefault();

                    // Trigger the click event of the whoisButton
                    document.getElementById('whoisButton').click();
                }
            });

            document.getElementById('whoisButton').addEventListener('click', function() {
                if (!validateInput()) return;
                var domain = document.getElementById('domainInput').value.trim();

                // Get the CAPTCHA input element
                var captchaInput = document.getElementById('captchaInput');

                // Initialize captcha with an empty string
                var captcha = '';

                // Check if the CAPTCHA element exists and is not disabled
                if (captchaInput && !captchaInput.disabled) {
                    captcha = captchaInput.value; // Assign the value of the CAPTCHA input
                }
                
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
                    document.getElementById('bottom').style.display = 'block';
                    if (captchaInput && !captchaInput.disabled) {
                        // Reload captcha after a successful response
                        document.getElementById('captchaImg').src = 'captcha.php?' + Math.random();
                    }
                })
                .catch(error => {
                    console.error('Error:', error); // Log the error to the console
                    document.getElementById('result').innerText = 'Error: ' + error.message; // Display the error message on the page
                });
            });

            document.getElementById('rdapButton').addEventListener('click', function() {
                if (!validateInput()) return;
                var domain = document.getElementById('domainInput').value.trim();

                // Get the CAPTCHA input element
                var captchaInput = document.getElementById('captchaInput');

                // Initialize captcha with an empty string
                var captcha = '';

                // Check if the CAPTCHA element exists and is not disabled
                if (captchaInput && !captchaInput.disabled) {
                    captcha = captchaInput.value; // Assign the value of the CAPTCHA input
                }
                
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
                        document.getElementById('bottom').style.display = 'block';
                        if (captchaInput && !captchaInput.disabled) {
                            // Reload captcha
                            document.getElementById('captchaImg').src = 'captcha.php?' + Math.random();
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error); // Log the error to the console
                    document.getElementById('result').innerText = 'Error: ' + error.message; // Display the error message on the page
                });
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
                    output += parseEntity(entity);
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

        function parseEntity(entity) {
            let output = '';

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

            if (entity.entities && entity.entities.length > 0) {
                entity.entities.forEach(subEntity => {
                    output += parseEntity(subEntity);
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