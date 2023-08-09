<?php

namespace EPP;

use XMLWriter;

class EppWriter {

    // Properties
    private $EPP_VERSIONS = ['1.0'];
    private $EPP_LANGUAGES = ['en-US', 'ua'];
    private $EPP_OBJECTS = [
        'contact' => 'urn:ietf:params:xml:ns:contact-1.0',
        'domain' => 'urn:ietf:params:xml:ns:domain-1.0',
        'host' => 'urn:ietf:params:xml:ns:host-1.0',
    ];
    private $command_handler_map = [
        'greeting' => '_greeting',
        'login' => '_common',
        'logout' => '_common',
        'check_contact' => '_check_contact',
        'info_contact' => '_info_contact',
        'transfer_contact' => '_transfer_contact',
        'create_contact' => '_create_contact',
        'delete_contact' => '_common',
        'update_contact' => '_common',
        'check_domain' => '_check_domain',
        'create_domain' => '_create_domain',
        'delete_domain' => '_common',
        'info_domain' => '_info_domain',
        'renew_domain' => '_renew_domain',
        'transfer_domain' => '_transfer_domain',
        'update_domain' => '_common',
        'check_host' => '_check_host',
        'create_host' => '_create_host',
        'delete_host' => '_common',
        'info_host' => '_info_host',
        'info_balance' => '_info_balance',
        'update_host' => '_common',
        'poll' => '_poll',
        'unknown' => '_common',
    ];

    // Methods
    public function epp_writer($resp) {
        $writer = new XMLWriter();
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->setIndentString('    ');
        $writer->startDocument('1.0', 'UTF-8', 'no');
        $writer->startElement('epp');
        $writer->writeAttribute('xmlns', 'urn:ietf:params:xml:ns:epp-1.0');
        $writer->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $writer->writeAttribute('xsi:schemaLocation', 'urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd');
        
        // Dynamic method call based on the command
        $handler = $this->command_handler_map[$resp['command']];
        $this->$handler($writer, $resp);
        
        $writer->endElement();  // Ending the 'epp' tag
        $writer->endDocument();
        return $writer->outputMemory();
    }
	
	private function epp_result_totext($code, $lang = 'en-US') {
        $resultTexts = [
        	1000 => [
                'code' => 'EPP_RS_SUCCESS',
                'en-US' => 'Command completed successfully',
                'fr-FR' => "la commande terminée avec succès"
        	],
        	1001 => [
                'code' => 'EPP_RS_PENDING',
                'en-US' => 'Command completed successfully; action pending',
                'fr-FR' => "la commande terminée avec succès ; l;'action est en suspens"
        	],
        	1300 => [
                'code' => 'EPP_RS_NOMSG',
                'en-US' => 'Command completed successfully; no messages',
                'fr-FR' => "la commande terminée avec succès ; il n'ya acun message"
        	],
        	1301 => [
                'code' => 'EPP_RS_ACK',
                'en-US' => 'Command completed successfully; ack to dequeue',
                'fr-FR' => "la commande terminé avec succès ; ack à retirer de la file d'attente"
        	],
        	1500 => [
                'code' => 'EPP_RS_END',
                'en-US' => 'Command completed successfully; ending session',
                'fr-FR' => "la commande terminé avec succès ; la session termine"
        	],
        	2000 => [
                'code' => 'EPP_RF_UNKCMD',
                'en-US' => 'Unknown command',
                'fr-FR' => "la commande est inconnue"
        	],
        	2001 => [
                'code' => 'EPP_RF_SYNTAX',
                'en-US' => 'Command syntax error',
                'fr-FR' => "erreur de syntaxe à la commande"
        	],
        	2002 => [
                'code' => 'EPP_RF_CMDUSE',
                'en-US' => 'Command use error',
                'fr-FR' => "erreur d'utilisation à la commande"
        	],
        	2003 => [
                'code' => 'EPP_RF_PARAM',
                'en-US' => 'Required parameter missing',
                'fr-FR' => "paramètre exigé est manquant"
        	],
        	2004 => [
                'code' => 'EPP_RF_VALRANGE',
                'en-US' => 'Parameter value range error',
                'fr-FR' => "la valeur de paramètre est hors d'intervalle"
        	],
        	2005 => [
                'code' => 'EPP_RF_VALSYNTAX',
                'en-US' => 'Parameter value syntax error',
                'fr-FR' => "erreur de syntaxe en valeur de paramètre"
        	],
        	2100 => [
                'code' => 'EPP_RF_PROTVERS',
                'en-US' => 'Unimplemented protocol version',
                'fr-FR' => "la version de protocole n'est pas mise en application"
        	],
        	2101 => [
                'code' => 'EPP_RF_UNIMPCMD',
                'en-US' => 'Unimplemented command',
                'fr-FR' => "la commande n'est pas mise en application"
        	],
        	2102 => [
                'code' => 'EPP_RF_UNIMPOPT',
                'en-US' => 'Unimplemented option',
                'fr-FR' => "l'option n'est pas mise en application"
        	],
        	2103 => [
                'code' => 'EPP_RF_UNIMPEXT',
                'en-US' => 'Unimplemented extension',
                'fr-FR' => "l'extension n'est pas mise en application"
        	],
        	2104 => [
                'code' => 'EPP_RF_BILLING',
                'en-US' => 'Billing failure',
                'fr-FR' => "panne de facturation"
        	],
        	2105 => [
                'code' => 'EPP_RF_NORENEW',
                'en-US' => 'Object is not eligible for renewal',
                'fr-FR' => "l'objet n'est pas habilité au renouvellement"
        	],
        	2106 => [
                'code' => 'EPP_RF_NOTRANSFER',
                'en-US' => 'Object is not eligible for transfer',
                'fr-FR' => "l'objet n'est pas éligible pour être transféré"
        	],
        	2200 => [
                'code' => 'EPP_RF_AUTHENTICATION',
                'en-US' => 'Authentication error',
                'fr-FR' => "erreur d'authentification"
        	],
        	2201 => [
                'code' => 'EPP_RF_AUTHORIZATION',
                'en-US' => 'Authorization error',
                'fr-FR' => "erreur d'autorisation"
        	],
        	2202 => [
                'code' => 'EPP_RF_INVAUTHOR',
                'en-US' => 'Invalid authorization information',
                'fr-FR' => "l'information d'autorisation est incorrecte"
        	],
        	2300 => [
                'code' => 'EPP_RF_PENDINGTRANSFER',
                'en-US' => 'Object pending transfer',
                'fr-FR' => "l'objet est transfert en suspens"
        	],
        	2301 => [
                'code' => 'EPP_RF_NOTPENDINGTRANSFER',
                'en-US' => 'Object not pending transfer',
                'fr-FR' => "l'objet n'est pas transfert en suspens"
        	],
        	2302 => [
                'code' => 'EPP_RF_EXISTS',
                'en-US' => 'Object exists',
                'fr-FR' => "l'objet existe"
        	],
        	2303 => [
                'code' => 'EPP_RF_NOTEXISTS',
                'en-US' => 'Object does not exist',
                'fr-FR' => "l'objet n'existe pas"
        	],
        	2304 => [
                'code' => 'EPP_RF_STATUS',
                'en-US' => 'Object status prohibits operation',
                'fr-FR' => "le statut de l'objet interdit cette exécution"
        	],
        	2305 => [
                'code' => 'EPP_RF_INUSE',
                'en-US' => 'Object association prohibits operation',
                'fr-FR' => "l'assocation de l'objet interdit cette exécution"
        	],
        	2306 => [
                'code' => 'EPP_RF_POLICYPARAM',
                'en-US' => 'Parameter value policy error',
                'fr-FR' => "erreur de politique en valeur du paramètre"
        	],
        	2307 => [
                'code' => 'EPP_RF_UNIMPLSERVICE',
                'en-US' => 'Unimplemented object service',
                'fr-FR' => "le service d'objet n'est pas mis en application"
        	],
        	2308 => [
                'code' => 'EPP_RF_DATAMGT',
                'en-US' => 'Data management policy violation',
                'fr-FR' => "violation de la politique de gestion des données"
        	],
        	2400 => [
                'code' => 'EPP_RF_FAIL',
                'en-US' => 'Command failed',
                'fr-FR' => "la commande a échoué"
        	],
        	2500 => [
                'code' => 'EPP_RF_CLOSING',
                'en-US' => 'Command failed; server closing connection',
                'fr-FR' => "la commande a échoué ; le serveur ferme la connexion"
        	],
        	2501 => [
                'code' => 'EPP_RF_AUTHCLOSING',
                'en-US' => 'Authentiction error; server closing connection',
                'fr-FR' => "erreur d'authentification ; le serveur ferme la connexion"
        	],
        	2502 => [
                'code' => 'EPP_RF_SESSIONLIMIT',
                'en-US' => 'Session limit exceeded; server closing connection',
                'fr-FR' => "la limite de session a été dépassée ; le serveur ferme la connexion"
        	]
        ];

        if (isset($resultTexts[$code][$lang])) {
            return $resultTexts[$code][$lang];
        }

        // Fallback to English if the specified language text doesn't exist
        if (isset($resultTexts[$code]['en-US'])) {
            return $resultTexts[$code]['en-US'];
        }

        // Return a default message if the code is not found
        return 'Unknown response code';
    }
	
    private function epp_success($resultCode) {
        // Typically, EPP result codes for successful commands are in the range of 1000-1999.
        // But you might need to adjust this range based on your specific EPP server responses.
        return $resultCode >= 1000 && $resultCode <= 1999;
    }

    private function _greeting($writer, $resp) {
        $writer->startElement('greeting');
        $writer->writeElement('svID', $resp['svID']);
        $writer->writeElement('svDate', date('c'));  // Using PHP's date function with 'c' format as a placeholder for EPP date
        $writer->startElement('svcMenu');
        
        foreach ($this->EPP_VERSIONS as $ver) {
            $writer->writeElement('version', $ver);
        }
        
        foreach ($this->EPP_LANGUAGES as $lang) {
            $writer->writeElement('lang', $lang);
        }

        foreach ($this->EPP_OBJECTS as $obj => $uri) {
            $writer->writeElement('objURI', $uri);
        }

        $writer->endElement();  // End of 'svcMenu'
        $writer->endElement();  // End of 'greeting'
    }

    private function _preamble($writer, $resp) {
        $lang = 'en-US';
        if (isset($resp['lang'])) {
            $lang = $resp['lang'];
        }

        $code = $resp['resultCode'];

        $writer->startElement('response');
            $writer->startElement('result');
            $writer->writeAttribute('code', $code);

            $msg = $this->epp_result_totext($code, $lang);
            if (isset($resp['human_readable_message'])) {
                $msg = $this->epp_result_totext($code, $lang) . ' : ' . $resp['human_readable_message'];
            }

            $writer->writeElement('msg', $msg);

            if (isset($resp['optionalValue'])) {
                $writer->startElement('value');
                if (isset($resp['xmlns_obj']) && isset($resp['xmlns_obj_value'])) {
                    $writer->writeAttribute($resp['xmlns_obj'], $resp['xmlns_obj_value']);
                }
                if (isset($resp['obj_elem']) && isset($resp['obj_elem_value'])) {
                    $writer->writeElement($resp['obj_elem'], $resp['obj_elem_value']);
                }
                $writer->endElement();  // End of 'value'
            }

            $writer->endElement();  // End of 'result'
    }

    private function _postamble($writer, $resp) {
        if (isset($resp['clTRID']) || isset($resp['svTRID'])) {
            $writer->startElement('trID');
            $writer->writeElement('clTRID', $resp['clTRID']);
            $writer->writeElement('svTRID', $resp['svTRID']);
            $writer->endElement();  // End of 'trID'
        }
        $writer->endElement();  // End of 'response'
    }

    private function _common($writer, $resp) {
        $this->_preamble($writer, $resp);
        $this->_postamble($writer, $resp);
    }

    private function _poll($writer, $resp) {
        $this->_preamble($writer, $resp);
        
        if ($resp['resultCode'] == 1000) {
            $writer->startElement('msgQ');
            $writer->writeAttribute('count', $resp['count']);
            $writer->writeAttribute('id', $resp['id']);
            $writer->endElement();  // End of 'msgQ'
        }
        elseif ($resp['resultCode'] == 1301) {
            $writer->startElement('msgQ');
            $writer->writeAttribute('count', $resp['count']);
            $writer->writeAttribute('id', $resp['id']);
            $writer->writeElement('qDate', $resp['qDate']);
            $writer->writeElement('msg', $resp['msg'], ['lang' => $resp['lang']]);
            $writer->endElement();  // End of 'msgQ'
        if ($resp['poll_msg_type'] === 'lowBalance') {
            $writer->startElement('resData');
                $writer->startElement('lowbalance-poll:pollData');
                $writer->writeAttribute('xmlns:lowbalance-poll', 'http://www.verisign.com/epp/lowbalance-poll-1.0');
                $writer->writeAttribute('xsi:schemaLocation', 'http://www.verisign.com/epp/lowbalance-poll-1.0 lowbalance-poll-1.0.xsd');
                $writer->writeElement('lowbalance-poll:registrarName', $resp['registrarName']);
                $writer->writeElement('lowbalance-poll:creditLimit', $resp['creditLimit']);
                $writer->writeElement('lowbalance-poll:creditThreshold', $resp['creditThreshold'], ['type' => $resp['creditThresholdType']]);
                $writer->writeElement('lowbalance-poll:availableCredit', $resp['availableCredit']);
                $writer->endElement();  // End of 'lowbalance-poll:pollData'
            $writer->endElement();  // End of 'resData'
        } elseif ($resp['poll_msg_type'] === 'domainTransfer') {
            $writer->startElement('resData');
                $writer->startElement('domain:trnData');
                $writer->writeAttribute('xmlns:domain', 'urn:ietf:params:xml:ns:domain-1.0');
                $writer->writeElement('domain:name', $resp['name']);
                $writer->writeElement('domain:trStatus', $resp['obj_trStatus']);
                $writer->writeElement('domain:reID', $resp['obj_reID']);
                $writer->writeElement('domain:reDate', $resp['obj_reDate']);
                $writer->writeElement('domain:acID', $resp['obj_acID']);
                $writer->writeElement('domain:acDate', $resp['obj_acDate']);
                if (isset($resp['obj_exDate'])) {
                    $writer->writeElement('domain:exDate', $resp['obj_exDate']);
                }
                $writer->endElement();  // End of 'domain:trnData'
            $writer->endElement();  // End of 'resData'
        } elseif ($resp['poll_msg_type'] === 'contactTransfer') {
            $writer->startElement('resData');
                $writer->startElement('contact:trnData');
                $writer->writeAttribute('xmlns:contact', 'urn:ietf:params:xml:ns:contact-1.0');
                $writer->writeElement('contact:id', $resp['identifier']);
                $writer->writeElement('contact:trStatus', $resp['obj_trStatus']);
                $writer->writeElement('contact:reID', $resp['obj_reID']);
                $writer->writeElement('contact:reDate', $resp['obj_reDate']);
                $writer->writeElement('contact:acID', $resp['obj_acID']);
                $writer->writeElement('contact:acDate', $resp['obj_acDate']);
                $writer->endElement();  // End of 'contact:trnData'
            $writer->endElement();  // End of 'resData'
        }
        }

    }

    private function _check_contact($writer, $resp) {
        $this->_preamble($writer, $resp);
        
        if ($this->epp_success($resp['resultCode'])) {

            $writer->startElement('resData');
                $writer->startElement('contact:chkData');
                $writer->writeAttribute('xmlns:contact', 'urn:ietf:params:xml:ns:contact-1.0');
                $writer->writeAttribute('xsi:schemaLocation', 'urn:ietf:params:xml:ns:contact-1.0 contact-1.0.xsd');
                
                foreach ($resp['ids'] as $ids) {
                    $writer->startElement('contact:cd');
                        $writer->writeElement('contact:id', $ids[0], ['avail' => $ids[1]]);
                        if (isset($ids[2])) {
                            $writer->writeElement('contact:reason', $ids[2]);
                        }
                    $writer->endElement();  // End of 'contact:cd'
                }
                
                $writer->endElement();  // End of 'contact:chkData'
            $writer->endElement();  // End of 'resData'
        }

        $this->_postamble($writer, $resp);
    }
	
    private function _info_contact($writer, $resp) {
        $this->_preamble($writer, $resp);
        
        if ($this->epp_success($resp['resultCode'])) {

            $writer->startElement('resData');
                $writer->startElement('contact:infData');
                $writer->writeAttribute('xmlns:contact', 'urn:ietf:params:xml:ns:contact-1.0');
                $writer->writeAttribute('xsi:schemaLocation', 'urn:ietf:params:xml:ns:contact-1.0 contact-1.0.xsd');
                $writer->writeElement('contact:id', $resp['id']);
                $writer->writeElement('contact:roid', $resp['roid']);
                // Handle 'contact:status'
                if (isset($resp['status']) && is_array($resp['status'])) {
                    foreach ($resp['status'] as $s) {
                        if (isset($s[1]) && isset($s[2])) {
                            $writer->writeElement('contact:status', $s[2], ['s' => $s[0], 'lang' => $s[1]]);
                        } else {
                            $writer->startElement('contact:status');
                            $writer->writeAttribute('s', $s[0]);
                            $writer->endElement();
                        }
                    }
                }
                
                // Handle 'contact:postalInfo'
                foreach ($resp['postal'] as $t => $postalData) {
                    $writer->startElement('contact:postalInfo');
                    $writer->writeAttribute('type', $t);
                    $writer->writeElement('contact:name', $postalData['name']);
                    $writer->writeElement('contact:org', $postalData['org']);
                    $writer->startElement('contact:addr');
                        foreach ($postalData['street'] as $s) {
                            if ($s) {
                                $writer->writeElement('contact:street', $s);
                            }
                        }
                        $writer->writeElement('contact:city', $postalData['city']);
                        if (isset($postalData['sp'])) {
                            $writer->writeElement('contact:sp', $postalData['sp']);
                        }
                        if (isset($postalData['pc'])) {
                            $writer->writeElement('contact:pc', $postalData['pc']);
                        }
                        $writer->writeElement('contact:cc', $postalData['cc']);
                    $writer->endElement();  // End of 'contact:addr'
                    $writer->endElement();  // End of 'contact:postalInfo'
                }
                
                // Handling 'contact:voice' and its optional attribute
                if (isset($resp['voice_x'])) {
                    $writer->writeElement('contact:voice', $resp['voice'], ['x' => $resp['voice_x']]);
                } else {
                    $writer->writeElement('contact:voice', $resp['voice']);
                }
                
                // Handling 'contact:fax' and its optional attribute
                if (isset($resp['fax_x'])) {
                    $writer->writeElement('contact:fax', $resp['fax'], ['x' => $resp['fax_x']]);
                } else {
                    $writer->writeElement('contact:fax', $resp['fax']);
                }
                
                $writer->writeElement('contact:email', $resp['email']);
                $writer->writeElement('contact:clID', $resp['clID']);
                $writer->writeElement('contact:crID', $resp['crID']);
                $writer->writeElement('contact:crDate', $resp['crDate']);
                if (isset($resp['upID'])) {
                    $writer->writeElement('contact:upID', $resp['upID']);
                }
                if (isset($resp['upDate'])) {
                    $writer->writeElement('contact:upDate', $resp['upDate']);
                }
                if (isset($resp['trDate'])) {
                    $writer->writeElement('contact:trDate', $resp['trDate']);
                }
                
                // Handling 'contact:authInfo'
                if ($resp['authInfo'] === 'valid') {
                    $writer->startElement('contact:authInfo');
                    if ($resp['authInfo_type'] === 'pw') {
                        $writer->writeElement('contact:pw', $resp['authInfo_val']);
                    } elseif ($resp['authInfo_type'] === 'ext') {
                        $writer->writeElement('contact:ext', $resp['authInfo_val']);
                    }
                    $writer->endElement();  // End of 'contact:authInfo'
                }

                $writer->endElement();  // End of 'contact:infData'
            $writer->endElement();  // End of 'resData'

            // Handling the extension part
            if (isset($resp['nin']) && isset($resp['nin_type'])) {
                $writer->startElement('extension');
                    $writer->startElement('identExt:infData');
                        $writer->writeAttribute('xmlns:identExt', 'http://www.nic.xx/XXNIC-EPP/identExt-1.0');
                        $writer->writeAttribute('xsi:schemaLocation', 'http://www.nic.xx/XXNIC-EPP/identExt-1.0 identExt-1.0.xsd');
            
                        $writer->startElement('identExt:nin');
                            $writer->writeAttribute('type', $resp['nin_type']);
                            $writer->text($resp['nin']);
                        $writer->endElement();  // End of 'identExt:nin'
                    
                    $writer->endElement();  // End of 'identExt:infData'
                $writer->endElement();  // End of 'extension'
            }
        }

        $this->_postamble($writer, $resp);
    }

    private function _transfer_contact($writer, $resp) {
        $this->_preamble($writer, $resp);
        
        if ($this->epp_success($resp['resultCode'])) {
            $writer->startElement('resData');
                $writer->startElement('contact:trnData');
                $writer->writeAttribute('xmlns:contact', 'urn:ietf:params:xml:ns:contact-1.0');
                $writer->writeAttribute('xsi:schemaLocation', 'urn:ietf:params:xml:ns:contact-1.0 contact-1.0.xsd');
                $writer->writeElement('contact:id', $resp['id']);
                $writer->writeElement('contact:trStatus', $resp['trStatus']);
                $writer->writeElement('contact:reID', $resp['reID']);
                $writer->writeElement('contact:reDate', $resp['reDate']);
                $writer->writeElement('contact:acID', $resp['acID']);
                $writer->writeElement('contact:acDate', $resp['acDate']);
                $writer->endElement();  // End of 'contact:trnData'
            $writer->endElement();  // End of 'resData'
        }

        $this->_postamble($writer, $resp);
    }

    private function _create_contact($writer, $resp) {
        $this->_preamble($writer, $resp);
        
        if ($this->epp_success($resp['resultCode'])) {
            $writer->startElement('resData');
                $writer->startElement('contact:creData');
                $writer->writeAttribute('xmlns:contact', 'urn:ietf:params:xml:ns:contact-1.0');
                $writer->writeAttribute('xsi:schemaLocation', 'urn:ietf:params:xml:ns:contact-1.0 contact-1.0.xsd');
                $writer->writeElement('contact:id', $resp['id']);
                $writer->writeElement('contact:crDate', $resp['crDate']);
                $writer->endElement();  // End of 'contact:creData'
            $writer->endElement();  // End of 'resData'
        }

        $this->_postamble($writer, $resp);
    }

    private function _check_domain($writer, $resp) {
        $this->_preamble($writer, $resp);
        
        if ($this->epp_success($resp['resultCode'])) {
            $writer->startElement('resData');
                $writer->startElement('domain:chkData');
                $writer->writeAttribute('xmlns:domain', 'urn:ietf:params:xml:ns:domain-1.0');
                $writer->writeAttribute('xsi:schemaLocation', 'urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd');
                foreach ($resp['names'] as $names) {
                    $writer->startElement('domain:cd');
                        $writer->writeElement('domain:name', $names[0], ['avail' => $names[1]]);
                        if (isset($names[2])) {
                            $writer->writeElement('domain:reason', $names[2]);
                        }
                    $writer->endElement();  // End of 'domain:cd'
                }
                $writer->endElement();  // End of 'domain:chkData'
            $writer->endElement();  // End of 'resData'
        }

        $this->_postamble($writer, $resp);
    }

    private function _create_domain($writer, $resp) {
        $this->_preamble($writer, $resp);
        
        if ($this->epp_success($resp['resultCode'])) {
            $writer->startElement('resData');
                $writer->startElement('domain:creData');
                $writer->writeAttribute('xmlns:domain', 'urn:ietf:params:xml:ns:domain-1.0');
                $writer->writeAttribute('xsi:schemaLocation', 'urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd');
                $writer->writeElement('domain:name', $resp['name']);
                $writer->writeElement('domain:crDate', $resp['crDate']);
                $writer->writeElement('domain:exDate', $resp['exDate']);
                $writer->endElement();  // End of 'domain:creData'
            $writer->endElement();  // End of 'resData'
        }

        $this->_postamble($writer, $resp);
    }

    private function _info_domain($writer, $resp) {
        $this->_preamble($writer, $resp);
        
        if ($this->epp_success($resp['resultCode'])) {
            $writer->startElement('resData');
                $writer->startElement('domain:infData');
                $writer->writeAttribute('xmlns:domain', 'urn:ietf:params:xml:ns:domain-1.0');
                $writer->writeAttribute('xsi:schemaLocation', 'urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd');
                $writer->writeElement('domain:name', $resp['name']);
                $writer->writeElement('domain:roid', $resp['roid']);
                                foreach ($resp['status'] as $s) {
                    if (isset($s[1]) && isset($s[2])) {
                        $writer->writeElement('domain:status', $s[2], ['s' => $s[0], 'lang' => $s[1]]);
                    } else {
                        $writer->startElement('domain:status');
                        $writer->writeAttribute('s', $s[0]);
                        $writer->endElement();
                    }
                }
                
                if (isset($resp['registrant'])) {
                    $writer->writeElement('domain:registrant', $resp['registrant']);
                }
                foreach ($resp['contact'] as $t) {
                    $writer->writeElement('domain:contact', $t[1], ['type' => $t[0]]);
                }
                if ($resp['return_ns']) {
                    $writer->startElement('domain:ns');
                    foreach ($resp['hostObj'] as $n) {
                        $writer->writeElement('domain:hostObj', $n);
                    }
                    $writer->endElement();  // End of 'domain:ns'
                }
                if ($resp['return_host']) {
                    foreach ($resp['host'] as $h) {
                        $writer->writeElement('domain:host', $h);
                    }
                }
                $writer->writeElement('domain:clID', $resp['clID']);
                if (isset($resp['crID'])) {
                    $writer->writeElement('domain:crID', $resp['crID']);
                }
                if (isset($resp['crDate'])) {
                    $writer->writeElement('domain:crDate', $resp['crDate']);
                }
                if (isset($resp['exDate'])) {
                    $writer->writeElement('domain:exDate', $resp['exDate']);
                }
                if (isset($resp['upID'])) {
                    $writer->writeElement('domain:upID', $resp['upID']);
                }
                if (isset($resp['upDate'])) {
                    $writer->writeElement('domain:upDate', $resp['upDate']);
                }
                if (isset($resp['trDate'])) {
                    $writer->writeElement('domain:trDate', $resp['trDate']);
                }
                if ($resp['authInfo'] == 'valid') {
                    $writer->startElement('domain:authInfo');
                    if ($resp['authInfo_type'] == 'pw') {
                        $writer->writeElement('domain:pw', $resp['authInfo_val']);
                    } elseif ($resp['authInfo_type'] == 'ext') {
                        $writer->writeElement('domain:ext', $resp['authInfo_val']);
                    }
                    $writer->endElement();  // End of 'domain:authInfo'
                }

                $writer->endElement();  // End of 'domain:infData'
            $writer->endElement();  // End of 'resData'

            // Handling the extension part
            $writer->startElement('extension');
                $writer->startElement('rgp:infData');
                $writer->writeAttribute('xmlns:rgp', 'urn:ietf:params:xml:ns:rgp-1.0');
                $writer->writeAttribute('xsi:schemaLocation', 'urn:ietf:params:xml:ns:rgp-1.0 rgp-1.0.xsd');
                $writer->startElement('rgp:rgpStatus');
                $writer->writeAttribute('s', $resp['rgpstatus']);
                $writer->endElement();  // End of 'rgp:rgpStatus'
                $writer->endElement();  // End of 'rgp:infData'
            $writer->endElement();  // End of 'extension'
        }

        $this->_postamble($writer, $resp);
    }

    private function _renew_domain($writer, $resp) {
        $this->_preamble($writer, $resp);
        
        if ($this->epp_success($resp['resultCode'])) {
            $writer->startElement('resData');
                $writer->startElement('domain:renData');
                $writer->writeAttribute('xmlns:domain', 'urn:ietf:params:xml:ns:domain-1.0');
                $writer->writeAttribute('xsi:schemaLocation', 'urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd');
                $writer->writeElement('domain:name', $resp['name']);
                $writer->writeElement('domain:exDate', $resp['exDate']);
                $writer->endElement();  // End of 'domain:renData'
            $writer->endElement();  // End of 'resData'
        }

        $this->_postamble($writer, $resp);
    }

    private function _transfer_domain($writer, $resp) {
        $this->_preamble($writer, $resp);
        
        if ($this->epp_success($resp['resultCode'])) {
            $writer->startElement('resData');
                $writer->startElement('domain:trnData');
                $writer->writeAttribute('xmlns:domain', 'urn:ietf:params:xml:ns:domain-1.0');
                $writer->writeAttribute('xsi:schemaLocation', 'urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd');
                $writer->writeElement('domain:name', $resp['name']);
                $writer->writeElement('domain:trStatus', $resp['trStatus']);
                $writer->writeElement('domain:reID', $resp['reID']);
                $writer->writeElement('domain:reDate', $resp['reDate']);
                $writer->writeElement('domain:acID', $resp['acID']);
                $writer->writeElement('domain:acDate', $resp['acDate']);
                if (isset($resp['exDate'])) {
                    $writer->writeElement('domain:exDate', $resp['exDate']);
                }
                $writer->endElement();  // End of 'domain:trnData'
            $writer->endElement();  // End of 'resData'
        }

        $this->_postamble($writer, $resp);
    }


    private function _check_host($writer, $resp) {
        $this->_preamble($writer, $resp);
        
        if ($this->epp_success($resp['resultCode'])) {
            $writer->startElement('resData');
                $writer->startElement('host:chkData');
                $writer->writeAttribute('xmlns:host', 'urn:ietf:params:xml:ns:host-1.0');
                $writer->writeAttribute('xsi:schemaLocation', 'urn:ietf:params:xml:ns:host-1.0 host-1.0.xsd');
                foreach ($resp['names'] as $n) {
                    $writer->startElement('host:cd');
                    $writer->writeElement('host:name', $n[0], ['avail' => $n[1]]);
                    if (isset($n[2])) {
                        $writer->writeElement('host:reason', $n[2]);
                    }
                    $writer->endElement();  // End of 'host:cd'
                }
                $writer->endElement();  // End of 'host:chkData'
            $writer->endElement();  // End of 'resData'
        }

        $this->_postamble($writer, $resp);
    }

    private function _create_host($writer, $resp) {
        $this->_preamble($writer, $resp);
        
        if ($this->epp_success($resp['resultCode'])) {
            $writer->startElement('resData');
                $writer->startElement('host:creData');
                $writer->writeAttribute('xmlns:host', 'urn:ietf:params:xml:ns:host-1.0');
                $writer->writeAttribute('xsi:schemaLocation', 'urn:ietf:params:xml:ns:host-1.0 host-1.0.xsd');
                $writer->writeElement('host:name', $resp['name']);
                $writer->writeElement('host:crDate', $resp['crDate']);
                $writer->endElement();  // End of 'host:creData'
            $writer->endElement();  // End of 'resData'
        }

        $this->_postamble($writer, $resp);
    }

    private function _info_host($writer, $resp) {
        $this->_preamble($writer, $resp);
        
        if ($this->epp_success($resp['resultCode'])) {
            $writer->startElement('resData');
                $writer->startElement('host:infData');
                $writer->writeAttribute('xmlns:host', 'urn:ietf:params:xml:ns:host-1.0');
                $writer->writeAttribute('xsi:schemaLocation', 'urn:ietf:params:xml:ns:host-1.0 host-1.0.xsd');
                $writer->writeElement('host:name', $resp['name']);
                $writer->writeElement('host:roid', $resp['roid']);
                if (isset($resp['status']) && count($resp['status'])) {
                    foreach ($resp['status'] as $s) {
                        if (isset($s[1]) && isset($s[2])) {
                            $writer->writeElement('host:status', $s[2], ['s' => $s[0], 'lang' => $s[1]]);
                        } else {
                            $writer->startElement('host:status');
                            $writer->writeAttribute('s', $s[0]);
                            $writer->endElement();
                        }
                    }
                }
                foreach ($resp['addr'] as $a) {
                    $writer->writeElement('host:addr', $a[1], ['ip' => 'v' . $a[0]]);
                }
                $writer->writeElement('host:clID', $resp['clID']);
                $writer->writeElement('host:crID', $resp['crID']);
                $writer->writeElement('host:crDate', $resp['crDate']);
                if (isset($resp['upID'])) {
                    $writer->writeElement('host:upID', $resp['upID']);
                }
                if (isset($resp['upDate'])) {
                    $writer->writeElement('host:upDate', $resp['upDate']);
                }
                if (isset($resp['trDate'])) {
                    $writer->writeElement('host:trDate', $resp['trDate']);
                }
                $writer->endElement();  // End of 'host:infData'
            $writer->endElement();  // End of 'resData'
        }

        $this->_postamble($writer, $resp);
    }

    private function _info_balance($writer, $resp) {
        $this->_preamble($writer, $resp);
        
        if ($this->epp_success($resp['resultCode'])) {
            $writer->startElement('resData');
                $writer->startElement('balance:infData');
                $writer->writeAttribute('xmlns:balance', 'http://www.verisign.com/epp/balance-1.0');
                $writer->writeAttribute('xsi:schemaLocation', 'http://www.verisign.com/epp/balance-1.0 balance-1.0.xsd');
                
                $writer->writeElement('balance:creditLimit', $resp['creditLimit']);
                $writer->writeElement('balance:balance', $resp['balance']);
                $writer->writeElement('balance:availableCredit', $resp['availableCredit']);
                
                $writer->startElement('balance:creditThreshold');
                if ($resp['thresholdType'] === 'fixed') {
                    $writer->writeElement('balance:fixed', $resp['creditThreshold']);
                } elseif ($resp['thresholdType'] === 'percent') {
                    $writer->writeElement('balance:percent', $resp['creditThreshold']);
                }
                $writer->endElement();  // End of 'balance:creditThreshold'
                
                $writer->endElement();  // End of 'balance:infData'
            $writer->endElement();  // End of 'resData'
        }

        $this->_postamble($writer, $resp);
    }

}

