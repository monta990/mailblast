<?php
/**
 * Mail Blast service.
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Mailblast\Service;


final class RecipientService
{
    public function countActiveUsersWithEmail(array $filter = []): int
        {
            global $DB;
    
            $type = $filter['type'] ?? 'all';
            $ids  = array_values(array_filter(array_map('intval', (array) ($filter['ids'] ?? [])), fn($id) => $id >= 0));
    
            $baseWhere = [
                'ue.is_default' => 1,
                'u.is_deleted'  => 0,
                'u.is_active'   => 1,
                'NOT'           => ['ue.email' => ''],
            ];
    
            $validTypes = ['all', 'users', 'entities', 'profiles'];
            if (!in_array($type, $validTypes, true) || ($type !== 'all' && empty($ids))) {
                return 0;
            }
    
            if ($type === 'all') {
                $result = $DB->request([
                    'COUNT'     => 'cpt',
                    'FROM'      => 'glpi_useremails AS ue',
                    'LEFT JOIN' => ['glpi_users AS u' => ['ON' => ['ue' => 'users_id', 'u' => 'id']]],
                    'WHERE'     => $baseWhere,
                ]);
                return (int) ($result->current()['cpt'] ?? 0);
            }
    
            $query = [
                'SELECT'    => ['u.id'],
                'FROM'      => 'glpi_useremails AS ue',
                'LEFT JOIN' => ['glpi_users AS u' => ['ON' => ['ue' => 'users_id', 'u' => 'id']]],
                'WHERE'     => $baseWhere,
            ];
    
            if ($type === 'users') {
                $query['WHERE']['u.id'] = $ids;
            } elseif ($type === 'profiles') {
                $query['LEFT JOIN']['glpi_profiles_users AS pu'] = ['ON' => ['pu' => 'users_id', 'u' => 'id']];
                $query['WHERE']['pu.profiles_id'] = $ids;
            } elseif ($type === 'entities') {
                $query['LEFT JOIN']['glpi_profiles_users AS pu'] = ['ON' => ['pu' => 'users_id', 'u' => 'id']];
                $query['WHERE'][] = self::buildEntityWhere($ids);
            }
    
            // Deduplicate in PHP — avoids GROUP BY quoting concerns and handles
            // users with multiple profile assignments appearing more than once.
            $seen = [];
            foreach ($DB->request($query) as $row) {
                $seen[(int) $row['id']] = true;
            }
            return count($seen);
        }

    public function buildEntityWhere(array $entityIds): array
        {
            $directAndSons = [];
            $ancestors     = [];
            foreach ($entityIds as $id) {
                foreach (array_keys(getSonsOf('glpi_entities', $id)) as $sonId) {
                    $directAndSons[] = $sonId;
                }
                foreach (array_keys(getAncestorsOf('glpi_entities', $id)) as $ancId) {
                    $ancestors[] = $ancId;
                }
            }
            $directAndSons = array_values(array_unique($directAndSons));
            $ancestors     = array_values(array_unique($ancestors));
    
            $orParts = [];
            if (!empty($directAndSons)) {
                $orParts[] = ['pu.entities_id' => $directAndSons];
            }
            if (!empty($ancestors)) {
                $orParts[] = ['pu.entities_id' => $ancestors, 'pu.is_recursive' => 1];
            }
    
            if (empty($orParts)) {
                return ['pu.entities_id' => [-1]]; // match nothing
            }
            return count($orParts) === 1 ? $orParts[0] : ['OR' => $orParts];
        }

    public function getEntities(): array
        {
            global $DB;
            $result = [];
            foreach ($DB->request(['SELECT' => ['id', 'completename', 'email'], 'FROM' => 'glpi_entities', 'ORDER' => ['completename ASC']]) as $row) {
                $result[] = ['id' => (int) $row['id'], 'name' => (string) $row['completename'], 'email' => (string) ($row['email'] ?? '')];
            }
            return $result;
        }

    public function getProfiles(): array
        {
            global $DB;
            $result = [];
            foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_profiles', 'ORDER' => ['name ASC']]) as $row) {
                $result[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
            }
            return $result;
        }

    public function getUsersWithEmail(): array
        {
            global $DB;
            $result = [];
            foreach ($DB->request([
                'SELECT'    => ['u.id', 'u.name', 'u.firstname', 'u.realname', 'ue.email'],
                'FROM'      => 'glpi_useremails AS ue',
                'LEFT JOIN' => ['glpi_users AS u' => ['ON' => ['ue' => 'users_id', 'u' => 'id']]],
                'WHERE'     => ['ue.is_default' => 1, 'u.is_deleted' => 0, 'u.is_active' => 1, 'NOT' => ['ue.email' => '']],
                'ORDER'     => ['u.realname ASC', 'u.firstname ASC'],
            ]) as $row) {
                $name     = trim($row['firstname'] . ' ' . $row['realname']) ?: (string) $row['name'];
                $result[] = ['id' => (int) $row['id'], 'name' => $name, 'email' => (string) $row['email']];
            }
            return $result;
        }

    public function resolveUserEmail(int $userId): array
        {
            if ($userId <= 0) {
                return ['email' => '', 'name' => ''];
            }
    
            global $DB;
            $iter = $DB->request([
                'SELECT'    => ['ue.email', 'u.firstname', 'u.realname', 'u.name AS login'],
                'FROM'      => 'glpi_useremails AS ue',
                'LEFT JOIN' => ['glpi_users AS u' => ['ON' => ['ue' => 'users_id', 'u' => 'id']]],
                'WHERE'     => [
                    'ue.is_default' => 1,
                    'ue.users_id'   => $userId,
                    'u.is_deleted'  => 0,
                    'u.is_active'   => 1,
                ],
            ]);
    
            if (!$iter->count()) {
                return ['email' => '', 'name' => ''];
            }
    
            $row   = $iter->current();
            $email = trim((string) ($row['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['email' => '', 'name' => ''];
            }
    
            $name = trim((string) ($row['firstname'] . ' ' . $row['realname'])) ?: (string) ($row['login'] ?? '');
            return ['email' => $email, 'name' => $name];
        }

}
