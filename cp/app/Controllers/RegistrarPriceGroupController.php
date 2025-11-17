<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;
use League\ISO3166\ISO3166;
use Respect\Validation\Validator as v;
use App\Auth\Auth;

class RegistrarPriceGroupController extends Controller
{
    /*
     * List all price groups
     */
    public function index(Request $request, Response $response): Response
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $db = $this->container->get('db');
        $uri = $request->getUri()->getPath();
        $groups = $db->select('SELECT * FROM registrar_price_group ORDER BY name') ?? [];

        $registrarMap = $this->getRegistrarMap();

        // Convert registrar_ids into readable names
        foreach ($groups as &$g) {
            $ids = array_filter(array_map('intval', explode(',', $g['registrar_ids'])));
            $names = [];

            foreach ($ids as $id) {
                if (isset($registrarMap[$id])) {
                    $names[] = $registrarMap[$id];
                }
            }

            $g['registrar_names'] = $names;
        }

        // Default view for GET requests or if POST data is not set
        return view($response,'admin/registrars/price_groups/index.twig', [
            'groups' => $groups,
            'currentUri' => $uri,
        ]);
    }

    /*
     * Show create form
     */
    public function create(Request $request, Response $response): Response
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $registrars = $this->getRegistrars();
        $uri = $request->getUri()->getPath();

        // Default view for GET requests or if POST data is not set
        return view($response,'admin/registrars/price_groups/form.twig', [
            'group'      => null,
            'registrars' => $registrars,
            'selected'   => [],
            'currentUri' => $uri
        ]);
    }

    /*
     * Show edit form
     */
    public function edit(Request $request, Response $response, $args): Response
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        if ($args) {
            $id = trim(preg_replace('/\s+/', ' ', $args));
            $db = $this->container->get('db');
            $uri = $request->getUri()->getPath();
            $group = $db->selectRow('SELECT * FROM registrar_price_group WHERE id = ?', [ $id ]);

            if (!$group) {
                return $response->withStatus(404);
            }

            $registrars = $this->getRegistrars();
            $selected = array_filter(array_map('trim', explode(',', $group['registrar_ids'])));

            return view($response,'admin/registrars/price_groups/form.twig', [
                'group'      => $group,
                'registrars' => $registrars,
                'selected'   => $selected,
                'currentUri' => $uri
            ]);
        } else {
            // Redirect to the registrars view
            return $response->withHeader('Location', '/registrars')->withStatus(302);
        }
    }

    /*
     * Save create / edit
     */
    public function save(Request $request, Response $response): Response
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $data = (array) $request->getParsedBody();
        $db = $this->container->get('db');

        $id          = isset($data['id']) ? (int) $data['id'] : null;
        $name        = trim($data['name'] ?? '');
        $description = trim($data['description'] ?? '');
        $registrarIds = $data['registrar_ids'] ?? [];
        if (!is_array($registrarIds)) {
            $registrarIds = [];
        }

        // Normalize registrar_ids (comma-separated string)
        $registrarIds = array_filter(array_map('intval', (array) $registrarIds));
        $registrarIdsStr = implode(',', $registrarIds);

        if ($name === '') {
            $this->container->get('flash')->addMessage('error', 'Unable to proceed: group name is required');
            return $response->withHeader('Location', '/registrars/price-groups')->withStatus(302);
        }

        if (mb_strlen($name) > 64) {
            $name = mb_substr($name, 0, 64);
        }

        if ($description === '') {
            $this->container->get('flash')->addMessage('error', 'Unable to proceed: group description is required');
            return $response->withHeader('Location', '/registrars/price-groups')->withStatus(302);
        }

        if (mb_strlen($description) > 128) {
            $description = mb_substr($description, 0, 128);
        }

        if ($id) {
            $db->update(
                'registrar_price_group',
                [
                    'name' => $name,
                    'description' => $description,
                    'registrar_ids' => $registrarIdsStr
                ],
                [
                    'id' => $id
                ]
            );
            $currentDateTime = new \DateTime();
            $crdate = $currentDateTime->format('Y-m-d H:i:s.v');
            $this->container->get('flash')->addMessage('info', 'Price group ' . $id . ' has been updated successfully on ' . $crdate);
        } else {
            $db->insert(
                'registrar_price_group',
                [
                    'name' => $name,
                    'description' => $description,
                    'registrar_ids' => $registrarIdsStr
                ]
            );
            $group_id = $db->getLastInsertId();
            $currentDateTime = new \DateTime();
            $crdate = $currentDateTime->format('Y-m-d H:i:s.v');
            $this->container->get('flash')->addMessage('success', 'Price group ' . $group_id . ' has been created successfully on ' . $crdate);
        }

        return $response->withHeader('Location', '/registrars/price-groups')->withStatus(302);
    }

    /*
     * Show "apply group prices" form
     */
    public function showApplyForm(Request $request, Response $response): Response
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $db = $this->container->get('db');
        $uri = $request->getUri()->getPath();
        $groups = $db->select('SELECT * FROM registrar_price_group ORDER BY name');
        $tlds = $db->select('SELECT id, tld FROM domain_tld ORDER BY tld');

        $commands = ['create', 'renew', 'transfer'];

        return view($response,'admin/registrars/price_groups/apply.twig', [
            'groups'   => $groups,
            'tlds'     => $tlds,
            'commands' => $commands,
            'currentUri' => $uri
        ]);
    }

    /*
     * Apply group prices: creates/updates domain_price rows for all group registrars
     */
    public function apply(Request $request, Response $response): Response
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $data = (array) $request->getParsedBody();

        $groupId = isset($data['group_id']) ? (int) $data['group_id'] : 0;
        $tldid   = isset($data['tldid']) ? (int) $data['tldid'] : 0;
        $command = isset($data['command']) ? trim((string) $data['command']) : 'create';

        $allowedCommands = ['create', 'renew', 'transfer'];
        if (!in_array($command, $allowedCommands, true)) {
            $command = 'create';
        }

        // Collect prices from form (only fields that are filled)
        $priceFields = ['m0','m12','m24','m36','m48','m60','m72','m84','m96','m108','m120'];
        $prices = [];
        foreach ($priceFields as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $raw = $data[$field];

            // Treat empty string / null as "not set"
            if ($raw === '' || $raw === null) {
                continue;
            }

            // Normalize to float
            $value = (float) $raw;

            // Optional: ignore negative prices
            if ($value < 0) {
                continue;
            }

            $prices[$field] = $value;
        }

        if ($groupId > 0 && $tldid > 0 && !empty($prices)) {
            $this->applyGroupPrices($groupId, $tldid, $command, $prices);
            $this->container->get('flash')->addMessage('success', 'Group prices applied successfully');
        } else {
            $this->container->get('flash')->addMessage('error', 'Invalid input or no prices provided.');
        }

        return $response->withHeader('Location', '/registrars/price-groups/apply')->withStatus(302);
    }

    /*
     * Bulk writer: apply group prices into domain_price (per registrar)
     */
    private function applyGroupPrices(int $groupId, int $tldid, string $command, array $prices): void
    {
        /** @var \Delight\Db\PdoDatabase $db */
        $db = $this->container->get('db');

        // 1) Load registrar_ids for this group
        $row = $db->selectRow('SELECT registrar_ids FROM registrar_price_group WHERE id = ?', [ $groupId ]);

        if (!$row || empty($row['registrar_ids'])) {
            return;
        }

        $ids = array_filter(array_map('intval', explode(',', $row['registrar_ids'])));
        if (empty($ids)) {
            return;
        }

        // 2) Build query for insert/update
        $columns = array_keys($prices); // e.g. ['m0','m12',...]

        // tldid, command, registrar_id + dynamic price cols
        $insertColumns = array_merge(['tldid', 'command', 'registrar_id'], $columns);
        $insertColumnsSql = implode(',', $insertColumns);

        // ?, ?, ?, ?, ?, ...
        $placeholders = implode(',', array_fill(0, count($insertColumns), '?'));

        // m0 = VALUES(m0), m12 = VALUES(m12), ...
        $setSql = implode(
            ', ',
            array_map(
                static fn ($c) => "$c = VALUES($c)",
                $columns
            )
        );

        $sql = "
            INSERT INTO domain_price ($insertColumnsSql)
            VALUES ($placeholders)
            ON DUPLICATE KEY UPDATE $setSql
        ";

        // 3) Apply to every registrar in the group
        foreach ($ids as $registrarId) {
            $params = [
                $tldid,
                $command,
                (int) $registrarId,
            ];

            foreach ($columns as $c) {
                $params[] = $prices[$c];
            }

            $db->exec($sql, $params);
        }
    }

    /*
     * Helper: load registrars for select box
     */
    private function getRegistrars(): array
    {
        $db = $this->container->get('db');
        $registrars = $db->select('SELECT id, name FROM registrar ORDER BY name');
        return $registrars;
    }

    /*
     * Helper: return map by ID
     */
    private function getRegistrarMap(): array
    {
        $db = $this->container->get('db');
        $rows = $db->select('SELECT id, name FROM registrar ORDER BY name');

        $map = [];
        foreach ($rows as $r) {
            $map[$r['id']] = $r['name'];
        }

        return $map;
    }
}