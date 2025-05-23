<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DapiController extends Controller
{
    public function listDomains(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $db = $this->container->get('db');

        // Map fields to fully qualified columns
        $allowedFieldsMap = [
            'name' => 'd.name',
            'crdate' => 'd.crdate',
            'exdate' => 'd.exdate',
            'registrant_identifier' => 'c.identifier'
        ];

        // --- SORTING ---
        $sortField = 'd.crdate'; // default
        $sortDir = 'desc';
        if (!empty($params['order'])) {
            $orderParts = explode(',', $params['order']);
            if (count($orderParts) === 2) {
                $fieldCandidate = preg_replace('/[^a-zA-Z0-9_]/', '', $orderParts[0]);
                if (array_key_exists($fieldCandidate, $allowedFieldsMap)) {
                    $sortField = $allowedFieldsMap[$fieldCandidate];
                }
                $sortDir = strtolower($orderParts[1]) === 'asc' ? 'asc' : 'desc';
            }
        }

        // --- PAGINATION ---
        $page = 1;
        $size = 10;
        if (!empty($params['page'])) {
            $pageParts = explode(',', $params['page']);
            if (count($pageParts) === 2) {
                $pageNum = (int)$pageParts[0];
                $pageSize = (int)$pageParts[1];
                if ($pageNum > 0) {
                    $page = $pageNum;
                }
                if ($pageSize > 0) {
                    $size = $pageSize;
                }
            }
        }
        $offset = ($page - 1) * $size;

        // --- FILTERING ---
        $whereClauses = [];
        $bindParams = [];
        foreach ($params as $key => $value) {
            if (preg_match('/^filter\d+$/', $key)) {
                $fParts = explode(',', $value);
                if (count($fParts) === 3) {
                    list($fField, $fOp, $fVal) = $fParts;
                    $fField = preg_replace('/[^a-zA-Z0-9_]/', '', $fField);

                    // Ensure the field is allowed and fully qualify it
                    if (!array_key_exists($fField, $allowedFieldsMap)) {
                        // Skip unknown fields
                        continue;
                    }
                    $column = $allowedFieldsMap[$fField];

                    switch ($fOp) {
                        case 'eq':
                            $whereClauses[] = "$column = :f_{$key}";
                            $bindParams["f_{$key}"] = $fVal;
                            break;
                        case 'cs':
                            // If searching in 'name' and user might enter Cyrillic
                            if ($fField === 'name') {
                                // Convert to punycode
                                $punyVal = idn_to_ascii($fVal, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                                if ($punyVal !== false && $punyVal !== $fVal) {
                                    // Search for both punycode and original term
                                    // (d.name LIKE '%cyrillic%' OR d.name LIKE '%punycode%')
                                    $whereClauses[] = "($column LIKE :f_{$key}_original OR $column LIKE :f_{$key}_puny)";
                                    $bindParams["f_{$key}_original"] = "%$fVal%";
                                    $bindParams["f_{$key}_puny"] = "%$punyVal%";
                                } else {
                                    // Just search normally
                                    $whereClauses[] = "$column LIKE :f_{$key}";
                                    $bindParams["f_{$key}"] = "%$fVal%";
                                }
                            } else {
                                // Non-name field, just search as usual
                                $whereClauses[] = "$column LIKE :f_{$key}";
                                $bindParams["f_{$key}"] = "%$fVal%";
                            }
                            break;
                        case 'sw':
                            $whereClauses[] = "$column LIKE :f_{$key}";
                            $bindParams["f_{$key}"] = "$fVal%";
                            break;
                        case 'ew':
                            $whereClauses[] = "$column LIKE :f_{$key}";
                            $bindParams["f_{$key}"] = "%$fVal";
                            break;
                        // Add other cases if needed
                    }
                }
            }
        }
        
        // Check admin status and apply registrar filter if needed
        $registrarCondition = '';
        if ($_SESSION['auth_roles'] !== 0) { // not admin
            $registrarId = $_SESSION['auth_registrar_id'];
            $registrarCondition = "d.clid = :registrarId";
            $bindParams["registrarId"] = $registrarId;
        }

        // Base SQL
        $sqlBase = "
            FROM domain d
            LEFT JOIN contact c ON d.registrant = c.id
            LEFT JOIN domain_status ds ON d.id = ds.domain_id
        ";

        // Combine registrar condition and search filters
        if (!empty($whereClauses)) {
            // We have search conditions
            $filtersCombined = "(" . implode(" OR ", $whereClauses) . ")";
            if ($registrarCondition) {
                // If registrarCondition exists and we have filters
                // we do registrarCondition AND (filters OR...)
                $sqlWhere = "WHERE $registrarCondition AND $filtersCombined";
            } else {
                // No registrar restriction, just the filters
                $sqlWhere = "WHERE $filtersCombined";
            }
        } else {
            // No search filters
            if ($registrarCondition) {
                // Only registrar condition
                $sqlWhere = "WHERE $registrarCondition";
            } else {
                // No filters, no registrar condition
                $sqlWhere = '';
            }
        }

        // Count total results
        $totalSql = "SELECT COUNT(DISTINCT d.id) AS total $sqlBase $sqlWhere";
        $totalCount = $db->selectValue($totalSql, $bindParams);

        // Data query
        $selectFields = "
            d.id, 
            d.name, 
            d.crdate, 
            d.exdate, 
            d.rgpstatus, 
            c.identifier AS registrant_identifier,
            GROUP_CONCAT(ds.status) AS domain_status
        ";

        $dataSql = "
            SELECT $selectFields
            $sqlBase
            $sqlWhere
            GROUP BY d.id
            ORDER BY $sortField $sortDir
            LIMIT $offset, $size
        ";

        $records = $db->select($dataSql, $bindParams);

        // Ensure records is always an array
        if (!$records) {
            $records = [];
        }

        // Format API results
        foreach ($records as &$row) {
            // Check if name is punycode by checking if it starts with 'xn--'
            if (stripos($row['name'], 'xn--') === 0) {
                // Convert punycode to Unicode and store it in 'name'
                $unicode_name = idn_to_utf8($row['name'], IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                $row['name_o'] = $row['name']; // Keep the original punycode in 'name_o'
                $row['name'] = $unicode_name; // Store the Unicode version in 'name'
            } else {
                // For regular names, both 'name' and 'name_o' are the same
                $row['name_o'] = $row['name'];
            }

            // Format domain_status as array of {status: '...'} objects
            if (!empty($row['domain_status'])) {
                $statuses = explode(',', $row['domain_status']);
                $row['domain_status'] = array_map(function($status) {
                    return ['status' => $status];
                }, $statuses);
            } else {
                $row['domain_status'] = [];
            }
        }

        $payload = [
            'records' => $records,
            'results' => $totalCount
        ];

        $response = $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response;
    }
    
    public function listApplications(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $db = $this->container->get('db');

        // Map fields to fully qualified columns
        $allowedFieldsMap = [
            'name' => 'd.name',
            'crdate' => 'd.crdate',
            'exdate' => 'd.exdate',
            'registrant_identifier' => 'c.identifier'
        ];

        // --- SORTING ---
        $sortField = 'd.crdate'; // default
        $sortDir = 'desc';
        if (!empty($params['order'])) {
            $orderParts = explode(',', $params['order']);
            if (count($orderParts) === 2) {
                $fieldCandidate = preg_replace('/[^a-zA-Z0-9_]/', '', $orderParts[0]);
                if (array_key_exists($fieldCandidate, $allowedFieldsMap)) {
                    $sortField = $allowedFieldsMap[$fieldCandidate];
                }
                $sortDir = strtolower($orderParts[1]) === 'asc' ? 'asc' : 'desc';
            }
        }

        // --- PAGINATION ---
        $page = 1;
        $size = 10;
        if (!empty($params['page'])) {
            $pageParts = explode(',', $params['page']);
            if (count($pageParts) === 2) {
                $pageNum = (int)$pageParts[0];
                $pageSize = (int)$pageParts[1];
                if ($pageNum > 0) {
                    $page = $pageNum;
                }
                if ($pageSize > 0) {
                    $size = $pageSize;
                }
            }
        }
        $offset = ($page - 1) * $size;

        // --- FILTERING ---
        $whereClauses = [];
        $bindParams = [];
        foreach ($params as $key => $value) {
            if (preg_match('/^filter\d+$/', $key)) {
                $fParts = explode(',', $value);
                if (count($fParts) === 3) {
                    list($fField, $fOp, $fVal) = $fParts;
                    $fField = preg_replace('/[^a-zA-Z0-9_]/', '', $fField);

                    // Ensure the field is allowed and fully qualify it
                    if (!array_key_exists($fField, $allowedFieldsMap)) {
                        // Skip unknown fields
                        continue;
                    }
                    $column = $allowedFieldsMap[$fField];

                    switch ($fOp) {
                        case 'eq':
                            $whereClauses[] = "$column = :f_{$key}";
                            $bindParams["f_{$key}"] = $fVal;
                            break;
                        case 'cs':
                            // If searching in 'name' and user might enter Cyrillic
                            if ($fField === 'name') {
                                // Convert to punycode
                                $punyVal = idn_to_ascii($fVal, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                                if ($punyVal !== false && $punyVal !== $fVal) {
                                    // Search for both punycode and original term
                                    // (d.name LIKE '%cyrillic%' OR d.name LIKE '%punycode%')
                                    $whereClauses[] = "($column LIKE :f_{$key}_original OR $column LIKE :f_{$key}_puny)";
                                    $bindParams["f_{$key}_original"] = "%$fVal%";
                                    $bindParams["f_{$key}_puny"] = "%$punyVal%";
                                } else {
                                    // Just search normally
                                    $whereClauses[] = "$column LIKE :f_{$key}";
                                    $bindParams["f_{$key}"] = "%$fVal%";
                                }
                            } else {
                                // Non-name field, just search as usual
                                $whereClauses[] = "$column LIKE :f_{$key}";
                                $bindParams["f_{$key}"] = "%$fVal%";
                            }
                            break;
                        case 'sw':
                            $whereClauses[] = "$column LIKE :f_{$key}";
                            $bindParams["f_{$key}"] = "$fVal%";
                            break;
                        case 'ew':
                            $whereClauses[] = "$column LIKE :f_{$key}";
                            $bindParams["f_{$key}"] = "%$fVal";
                            break;
                        // Add other cases if needed
                    }
                }
            }
        }
        
        // Check admin status and apply registrar filter if needed
        $registrarCondition = '';
        if ($_SESSION['auth_roles'] !== 0) { // not admin
            $registrarId = $_SESSION['auth_registrar_id'];
            $registrarCondition = "d.clid = :registrarId";
            $bindParams["registrarId"] = $registrarId;
        }

        // Base SQL
        $sqlBase = "
            FROM application d
            LEFT JOIN contact c ON d.registrant = c.id
            LEFT JOIN application_status ds ON d.id = ds.domain_id
        ";

        // Combine registrar condition and search filters
        if (!empty($whereClauses)) {
            // We have search conditions
            $filtersCombined = "(" . implode(" OR ", $whereClauses) . ")";
            if ($registrarCondition) {
                // If registrarCondition exists and we have filters
                // we do registrarCondition AND (filters OR...)
                $sqlWhere = "WHERE $registrarCondition AND $filtersCombined";
            } else {
                // No registrar restriction, just the filters
                $sqlWhere = "WHERE $filtersCombined";
            }
        } else {
            // No search filters
            if ($registrarCondition) {
                // Only registrar condition
                $sqlWhere = "WHERE $registrarCondition";
            } else {
                // No filters, no registrar condition
                $sqlWhere = '';
            }
        }

        // Count total results
        $totalSql = "SELECT COUNT(DISTINCT d.id) AS total $sqlBase $sqlWhere";
        $totalCount = $db->selectValue($totalSql, $bindParams);

        // Data query
        $selectFields = "
            d.id, 
            d.name, 
            d.crdate, 
            d.exdate, 
            d.phase_type, 
            c.identifier AS registrant_identifier,
            GROUP_CONCAT(ds.status) AS application_status
        ";

        $dataSql = "
            SELECT $selectFields
            $sqlBase
            $sqlWhere
            GROUP BY d.id
            ORDER BY $sortField $sortDir
            LIMIT $offset, $size
        ";

        $records = $db->select($dataSql, $bindParams);

        // Ensure records is always an array
        if (!$records) {
            $records = [];
        }

        // Format API results
        foreach ($records as &$row) {
            // Check if name is punycode by checking if it starts with 'xn--'
            if (stripos($row['name'], 'xn--') === 0) {
                // Convert punycode to Unicode and store it in 'name'
                $unicode_name = idn_to_utf8($row['name'], IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                $row['name_o'] = $row['name']; // Keep the original punycode in 'name_o'
                $row['name'] = $unicode_name; // Store the Unicode version in 'name'
            } else {
                // For regular names, both 'name' and 'name_o' are the same
                $row['name_o'] = $row['name'];
            }

            // Format application_status as array of {status: '...'} objects
            if (!empty($row['application_status'])) {
                $statuses = explode(',', $row['application_status']);
                $row['application_status'] = array_map(function($status) {
                    return ['status' => $status];
                }, $statuses);
            } else {
                $row['application_status'] = [];
            }
        }

        $payload = [
            'records' => $records,
            'results' => $totalCount
        ];

        $response = $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response;
    }

    public function listPayments(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $db = $this->container->get('db');

        // Map fields to fully qualified columns for filtering/sorting
        // Adjust field names if needed
        $allowedFieldsMap = [
            'date' => 'ph.date',
            'registrar_id' => 'ph.registrar_id',
            'description' => 'ph.description',
            'amount' => 'ph.amount',
            'registrar_name' => 'r.name',
            'currency' => 'r.currency'
        ];

        // --- SORTING ---
        $sortField = 'ph.date'; // default sort by date
        $sortDir = 'desc';
        if (!empty($params['order'])) {
            $orderParts = explode(',', $params['order']);
            if (count($orderParts) === 2) {
                $fieldCandidate = preg_replace('/[^a-zA-Z0-9_]/', '', $orderParts[0]);
                if (array_key_exists($fieldCandidate, $allowedFieldsMap)) {
                    $sortField = $allowedFieldsMap[$fieldCandidate];
                }
                $sortDir = strtolower($orderParts[1]) === 'asc' ? 'asc' : 'desc';
            }
        }

        // --- PAGINATION ---
        $page = 1;
        $size = 10;
        if (!empty($params['page'])) {
            $pageParts = explode(',', $params['page']);
            if (count($pageParts) === 2) {
                $pageNum = (int)$pageParts[0];
                $pageSize = (int)$pageParts[1];
                if ($pageNum > 0) {
                    $page = $pageNum;
                }
                if ($pageSize > 0) {
                    $size = $pageSize;
                }
            }
        }
        $offset = ($page - 1) * $size;

        // --- FILTERING ---
        $whereClauses = [];
        $bindParams = [];
        foreach ($params as $key => $value) {
            if (preg_match('/^filter\d+$/', $key)) {
                $fParts = explode(',', $value);
                if (count($fParts) === 3) {
                    list($fField, $fOp, $fVal) = $fParts;
                    $fField = preg_replace('/[^a-zA-Z0-9_]/', '', $fField);

                    // Ensure the field is allowed and fully qualify it
                    if (!array_key_exists($fField, $allowedFieldsMap)) {
                        // Skip unknown fields
                        continue;
                    }
                    $column = $allowedFieldsMap[$fField];

                    switch ($fOp) {
                        case 'eq':
                            $whereClauses[] = "$column = :f_{$key}";
                            $bindParams["f_{$key}"] = $fVal;
                            break;
                        case 'cs':
                            $whereClauses[] = "$column LIKE :f_{$key}";
                            $bindParams["f_{$key}"] = "%$fVal%";
                            break;
                        case 'sw':
                            $whereClauses[] = "$column LIKE :f_{$key}";
                            $bindParams["f_{$key}"] = "$fVal%";
                            break;
                        case 'ew':
                            $whereClauses[] = "$column LIKE :f_{$key}";
                            $bindParams["f_{$key}"] = "%$fVal";
                            break;
                        // Add other cases if needed
                    }
                }
            }
        }
        
        // Check admin status and apply registrar filter if needed
        $registrarCondition = '';
        if ($_SESSION['auth_roles'] !== 0) { // not admin
            $registrarId = $_SESSION['auth_registrar_id'];
            $registrarCondition = "ph.registrar_id = :registrarId";
            $bindParams["registrarId"] = $registrarId;
        }

        // Base SQL
        $sqlBase = "
            FROM payment_history ph
            LEFT JOIN registrar r ON ph.registrar_id = r.id
        ";

        // Combine registrar condition and search filters
        if (!empty($whereClauses)) {
            // We have search conditions
            $filtersCombined = "(" . implode(" OR ", $whereClauses) . ")";
            if ($registrarCondition) {
                // If registrarCondition exists and we have filters
                // we do registrarCondition AND (filters OR...)
                $sqlWhere = "WHERE $registrarCondition AND $filtersCombined";
            } else {
                // No registrar restriction, just the filters
                $sqlWhere = "WHERE $filtersCombined";
            }
        } else {
            // No search filters
            if ($registrarCondition) {
                // Only registrar condition
                $sqlWhere = "WHERE $registrarCondition";
            } else {
                // No filters, no registrar condition
                $sqlWhere = '';
            }
        }

        // Count total results
        $totalSql = "SELECT COUNT(DISTINCT ph.id) AS total $sqlBase $sqlWhere";
        $totalCount = $db->selectValue($totalSql, $bindParams);

        // Data query
        $selectFields = "
            ph.id,
            ph.registrar_id,
            ph.date,
            ph.description,
            ph.amount,
            r.name AS registrar_name,
            r.currency
        ";

        $dataSql = "
            SELECT $selectFields
            $sqlBase
            $sqlWhere
            ORDER BY $sortField $sortDir
            LIMIT $offset, $size
        ";

        $records = $db->select($dataSql, $bindParams);

        // Ensure records is always an array
        if (!$records) {
            $records = [];
        }

        $payload = [
            'records' => $records,
            'results' => $totalCount
        ];

        $response = $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response;
    }
    
    public function listStatements(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $db = $this->container->get('db');

        // Map fields to fully qualified columns for filtering/sorting
        $allowedFieldsMap = [
            'date' => 'st.date',
            'registrar_id' => 'st.registrar_id',
            'command' => 'st.command',
            'domain_name' => 'st.domain_name',
            'length_in_months' => 'st.length_in_months',
            'fromS' => 'st.fromS',
            'toS' => 'st.toS',
            'amount' => 'st.amount',
            'registrar_name' => 'r.name',
            'currency' => 'r.currency'
        ];

        // --- SORTING ---
        $sortField = 'st.date'; // default sort by date
        $sortDir = 'desc';
        if (!empty($params['order'])) {
            $orderParts = explode(',', $params['order']);
            if (count($orderParts) === 2) {
                $fieldCandidate = preg_replace('/[^a-zA-Z0-9_]/', '', $orderParts[0]);
                if (array_key_exists($fieldCandidate, $allowedFieldsMap)) {
                    $sortField = $allowedFieldsMap[$fieldCandidate];
                }
                $sortDir = strtolower($orderParts[1]) === 'asc' ? 'asc' : 'desc';
            }
        }

        // --- PAGINATION ---
        $page = 1;
        $size = 10;
        if (!empty($params['page'])) {
            $pageParts = explode(',', $params['page']);
            if (count($pageParts) === 2) {
                $pageNum = (int)$pageParts[0];
                $pageSize = (int)$pageParts[1];
                if ($pageNum > 0) {
                    $page = $pageNum;
                }
                if ($pageSize > 0) {
                    $size = $pageSize;
                }
            }
        }
        $offset = ($page - 1) * $size;

        // --- FILTERING ---
        $whereClauses = [];
        $bindParams = [];
        foreach ($params as $key => $value) {
            if (preg_match('/^filter\d+$/', $key)) {
                $fParts = explode(',', $value);
                if (count($fParts) === 3) {
                    list($fField, $fOp, $fVal) = $fParts;
                    $fField = preg_replace('/[^a-zA-Z0-9_]/', '', $fField);

                    // Ensure the field is allowed and fully qualify it
                    if (!array_key_exists($fField, $allowedFieldsMap)) {
                        // Skip unknown fields
                        continue;
                    }
                    $column = $allowedFieldsMap[$fField];

                    switch ($fOp) {
                        case 'eq':
                            $whereClauses[] = "$column = :f_{$key}";
                            $bindParams["f_{$key}"] = $fVal;
                            break;
                        case 'cs':
                            $whereClauses[] = "$column LIKE :f_{$key}";
                            $bindParams["f_{$key}"] = "%$fVal%";
                            break;
                        case 'sw':
                            $whereClauses[] = "$column LIKE :f_{$key}";
                            $bindParams["f_{$key}"] = "$fVal%";
                            break;
                        case 'ew':
                            $whereClauses[] = "$column LIKE :f_{$key}";
                            $bindParams["f_{$key}"] = "%$fVal";
                            break;
                        // Add other cases if needed
                    }
                }
            }
        }

        // Check admin status and apply registrar filter if needed
        $registrarCondition = '';
        if ($_SESSION['auth_roles'] !== 0) { // not admin
            $registrarId = $_SESSION['auth_registrar_id'];
            $registrarCondition = "st.registrar_id = :registrarId";
            $bindParams["registrarId"] = $registrarId;
        }

        // Base SQL
        $sqlBase = "
            FROM statement st
            LEFT JOIN registrar r ON st.registrar_id = r.id
        ";

        // Combine registrar condition and search filters
        if (!empty($whereClauses)) {
            // We have search conditions
            $filtersCombined = "(" . implode(" OR ", $whereClauses) . ")";
            if ($registrarCondition) {
                // If registrarCondition exists and we have filters
                // we do registrarCondition AND (filters OR...)
                $sqlWhere = "WHERE $registrarCondition AND $filtersCombined";
            } else {
                // No registrar restriction, just the filters
                $sqlWhere = "WHERE $filtersCombined";
            }
        } else {
            // No search filters
            if ($registrarCondition) {
                // Only registrar condition
                $sqlWhere = "WHERE $registrarCondition";
            } else {
                // No filters, no registrar condition
                $sqlWhere = '';
            }
        }

        // Count total results
        $totalSql = "SELECT COUNT(DISTINCT st.id) AS total $sqlBase $sqlWhere";
        $totalCount = $db->selectValue($totalSql, $bindParams);

        // Data query
        $selectFields = "
            st.id,
            st.registrar_id,
            st.date,
            st.command,
            st.domain_name,
            st.length_in_months,
            st.fromS,
            st.toS,
            st.amount,
            r.name AS registrar_name,
            r.currency
        ";

        $dataSql = "
            SELECT $selectFields
            $sqlBase
            $sqlWhere
            ORDER BY $sortField $sortDir
            LIMIT $offset, $size
        ";

        $records = $db->select($dataSql, $bindParams);

        // Ensure records is always an array
        if (!$records) {
            $records = [];
        }

        $payload = [
            'records' => $records,
            'results' => $totalCount
        ];

        $response = $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response;
    }

    public function domainPrice(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $db = $this->container->get('db');

        $domain_name = $params['domain_name'] ?? '';
        $date_add = (int) ($params['date_add'] ?? 12);
        $command = $params['command'] ?? 'create';
        $currency = $params['currency'] ?? 'USD';
        $registrar_id = !empty($params['registrar_id']) ? $params['registrar_id'] : ($_SESSION['auth_registrar_id'] ?? null);

        $parts = extractDomainAndTLD($domain_name);
        $domain_extension = $parts['tld'] ?? null;

        $tld_id = null;
        if ($domain_extension !== null) {
            $tld_id = $db->selectValue('SELECT id FROM domain_tld WHERE tld = ?', ['.' . $domain_extension]);
        }

        $result = getDomainPrice($db, $domain_name, $tld_id, $date_add, $command, $registrar_id, $currency);

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

}