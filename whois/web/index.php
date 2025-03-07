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
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

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
    
    a {
        color: #007BFF;
        text-decoration: none;
        transition: color 0.3s ease, text-decoration 0.3s ease;
    }

    a:hover,
    a:focus {
        color: #0056b3;
        text-decoration: underline;
    }

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
                        captchaInput.value = '';
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
                        document.getElementById('bottom').style.display = 'block';
                    } else {
                        // Parse and display RDAP data
                        let output = parseRDAP(data);
                        document.getElementById('result').innerText = output;
                        document.getElementById('bottom').style.display = 'block';
                        if (captchaInput && !captchaInput.disabled) {
                            // Reload captcha
                            document.getElementById('captchaImg').src = 'captcha.php?' + Math.random();
                            captchaInput.value = '';
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error); // Log the error to the console
                    document.getElementById('result').innerText = 'Error: ' + error.message;
                    document.getElementById('bottom').style.display = 'block';
                });
            });
        });

        /**
         * Flattens the "entities" field.
         * The RDAP JSON sometimes nests arrays of entities.
         */
        function flattenEntities(entities) {
          let flat = [];
          entities.forEach(item => {
            if (Array.isArray(item)) {
              flat = flat.concat(item);
            } else if (typeof item === "object" && item !== null) {
              flat.push(item);
              // If an entity contains a nested entities array (for example, abuse contacts inside registrar)
              if (item.entities && Array.isArray(item.entities)) {
                flat = flat.concat(flattenEntities(item.entities));
              }
            }
          });
          return flat;
        }

        /**
         * Helper to extract a vCard field value by key from a vcardArray.
         */
        function getVCardValue(vcardArray, key) {
          if (!vcardArray || vcardArray.length < 2) return null;
          const props = vcardArray[1];
          const field = props.find(item => item[0] === key);
          return field ? field[3] : null;
        }

        /**
         * Main parser: Takes the RDAP JSON object and returns a WHOIS-style text output.
         */
        function parseRDAP(data) {
          let output = "";

          // Domain basic details
          output += `Domain Name: ${(data.ldhName || "N/A").toUpperCase()}\n`;
          output += `Domain ID: ${data.handle || "N/A"}\n\n`;

          // Domain status
          if (data.status && data.status.length) {
            output += "Status:\n";
            data.status.forEach(s => {
              output += `  - ${s}\n`;
            });
            output += "\n";
          }

          // Events (e.g., registration, expiration, last update)
          if (data.events && data.events.length) {
            output += "Events:\n";
            data.events.forEach(event => {
              // Capitalize event action for display
              const action = event.eventAction.charAt(0).toUpperCase() + event.eventAction.slice(1);
              output += `  ${action}: ${event.eventDate}\n`;
            });
            output += "\n";
          }

          // Nameservers
          if (data.nameservers && data.nameservers.length) {
            output += "Nameservers:\n";
            data.nameservers.forEach(ns => {
              output += `  - ${ns.ldhName || "N/A"}\n`;
            });
            output += "\n";
          }

          // Secure DNS info
          if (data.secureDNS) {
            output += "Secure DNS:\n";
            output += `  Zone Signed: ${data.secureDNS.zoneSigned}\n`;
            output += `  Delegation Signed: ${data.secureDNS.delegationSigned}\n\n`;
          }

          // Flatten all entities (registrar, registrant, admin, tech, billing, etc.)
          let allEntities = data.entities ? flattenEntities(data.entities) : [];

          // Registrar
          const registrar = allEntities.find(ent => ent.roles && ent.roles.includes("registrar"));
          if (registrar) {
            const regName = getVCardValue(registrar.vcardArray, "fn") || "N/A";
            output += `Registrar: ${regName}\n`;

            let ianaId = "";
            if (registrar.publicIds && Array.isArray(registrar.publicIds)) {
              const ianaObj = registrar.publicIds.find(pub => pub.type === "IANA Registrar ID");
              if (ianaObj) {
                ianaId = ianaObj.identifier;
              }
            }
            output += `IANA ID: ${ianaId}\n\n`;

            // Look for nested abuse contact within the registrar entity
            if (registrar.entities && Array.isArray(registrar.entities)) {
              const abuseContact = flattenEntities(registrar.entities).find(ent => ent.roles && ent.roles.includes("abuse"));
              if (abuseContact) {
                const abuseName = getVCardValue(abuseContact.vcardArray, "fn") || "N/A";
                const abuseEmail = getVCardValue(abuseContact.vcardArray, "email") || "N/A";
                const abuseTel = getVCardValue(abuseContact.vcardArray, "tel") || "N/A";
                output += "Registrar Abuse Contact:\n";
                output += `  Name: ${abuseName}\n`;
                output += `  Email: ${abuseEmail}\n`;
                output += `  Phone: ${abuseTel}\n`;
              }
            }
            output += "\n";
          }

          // Process other roles: registrant, admin, tech, billing
          const rolesToShow = ["registrant", "admin", "tech", "billing"];
          rolesToShow.forEach(role => {
            // Filter entities by role
            const ents = allEntities.filter(ent => ent.roles && ent.roles.includes(role));
            if (ents.length) {
              ents.forEach(ent => {
                const name = getVCardValue(ent.vcardArray, "fn") || "N/A";
                output += `${role.charAt(0).toUpperCase() + role.slice(1)} Contact: ${name}\n`;
                output += `  Handle: ${ent.handle || "N/A"}\n`;
                // Optionally, include organization and address if available
                const org = getVCardValue(ent.vcardArray, "org");
                if (org) {
                  output += `  Organization: ${org}\n`;
                }
                // You can add more fields as needed (e.g., email, phone)
                const email = getVCardValue(ent.vcardArray, "email");
                if (email) {
                  output += `  Email: ${email}\n`;
                }
                const tel = getVCardValue(ent.vcardArray, "tel");
                if (tel) {
                  output += `  Phone: ${tel}\n`;
                }
                const address = getVCardValue(ent.vcardArray, "adr");
                if (address) {
                  // Since the address is an array, filter out any empty parts and join them
                  const addrStr = Array.isArray(address) ? address.filter(part => part && part.trim()).join(', ') : address;
                  output += `  Address: ${addrStr}\n`;
                }
                output += "\n";
              });
            }
          });

          // Notices
          if (data.notices && data.notices.length) {
            output += "Notices:\n";
            data.notices.forEach(notice => {
              if (notice.title) {
                output += `  ${notice.title}\n`;
              }
              if (notice.description && Array.isArray(notice.description)) {
                notice.description.forEach(desc => {
                  output += `    ${desc}\n`;
                });
              }
              output += "\n";
            });
          }

          return output;
        }

    </script>
</body>
</html>