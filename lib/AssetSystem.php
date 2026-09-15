<?php
declare(strict_types=1);

final class AssetSystem
{
    public const ADMIN = 'Administrator';
    public const STAFF = 'Laboratory Staff';
    public const REQUESTER = 'Requester / Viewer';
    private const DATA_FILE = __DIR__ . '/../data/state.json';

    public static function load(): array
    {
        self::ensureDataFile();
        $contents = file_get_contents(self::DATA_FILE);
        $state = json_decode($contents ?: '', true);
        return is_array($state) ? $state : self::initialState();
    }

    public static function save(array $state): void
    {
        self::ensureDataFile();
        file_put_contents(self::DATA_FILE, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
    }

    public static function initialState(): array
    {
        return [
            'users' => [
                ['id' => 'U-001', 'name' => 'Maria Santos', 'role' => self::ADMIN],
                ['id' => 'U-002', 'name' => 'Ana Cruz', 'role' => self::STAFF],
                ['id' => 'U-003', 'name' => 'John Dela Cruz', 'role' => self::REQUESTER],
                ['id' => 'U-004', 'name' => 'Rina Flores', 'role' => self::REQUESTER],
            ],
            'equipment' => [
                ['id' => 'EQ-001', 'code' => 'LAP-001', 'name' => 'Laptop Dell XPS', 'status' => 'Available', 'location' => 'Room A-1', 'condition' => 'Good'],
                ['id' => 'EQ-002', 'code' => 'OSC-020', 'name' => 'Digital Oscilloscope', 'status' => 'Available', 'location' => 'Room B-2', 'condition' => 'Good'],
                ['id' => 'EQ-003', 'code' => 'PRO-014', 'name' => 'Projector', 'status' => 'Maintenance', 'location' => 'Workshop', 'condition' => 'In repair'],
                ['id' => 'EQ-004', 'code' => '3DP-009', 'name' => '3D Printer', 'status' => 'Available', 'location' => 'Lab 3', 'condition' => 'Good'],
            ],
            'requests' => [
                ['id' => 'BR-101', 'equipmentId' => 'EQ-001', 'requesterId' => 'U-003', 'status' => 'Pending', 'requestedDays' => 2, 'requestedOn' => date('Y-m-d'), 'notes' => 'Need for software testing lab'],
            ],
            'maintenance' => [
                ['id' => 'MT-201', 'equipmentId' => 'EQ-003', 'requesterId' => 'U-002', 'status' => 'Open', 'description' => 'Projector flickers during startup'],
            ],
            'auditLogs' => [
                ['id' => 'AL-901', 'userId' => 'U-003', 'action' => 'SUBMITTED', 'module' => 'Borrowing', 'recordId' => 'BR-101', 'description' => 'Borrowing request submitted for LAP-001', 'createdAt' => date('c')],
            ],
        ];
    }

    public static function user(array $state, string $id): ?array
    {
        foreach ($state['users'] as $user) {
            if ($user['id'] === $id) return $user;
        }
        return null;
    }

    public static function equipment(array $state, string $id): ?array
    {
        foreach ($state['equipment'] as $item) {
            if ($item['id'] === $id) return $item;
        }
        return null;
    }

    public static function canAccess(string $role, string $page): bool
    {
        $access = [
            self::ADMIN => ['dashboard', 'equipment', 'requests', 'maintenance', 'audit', 'users'],
            self::STAFF => ['dashboard', 'equipment', 'requests', 'maintenance'],
            self::REQUESTER => ['dashboard', 'requests', 'history'],
        ];
        return in_array($page, $access[$role] ?? [], true);
    }

    public static function submitRequest(array &$state, string $equipmentId, string $requesterId, int $days, string $notes): void
    {
        if (!self::user($state, $requesterId)) throw new RuntimeException('Authenticated user not found.');
        $equipment = self::equipment($state, $equipmentId);
        if (!$equipment) throw new RuntimeException('Equipment not found.');
        if ($equipment['status'] !== 'Available') throw new RuntimeException('BR-A4-01: Only available equipment may be requested.');
        $request = ['id' => 'BR-' . bin2hex(random_bytes(4)), 'equipmentId' => $equipmentId, 'requesterId' => $requesterId, 'status' => 'Pending', 'requestedDays' => max(1, min(30, $days)), 'requestedOn' => date('Y-m-d'), 'notes' => $notes];
        $state['requests'][] = $request;
        self::audit($state, $requesterId, 'SUBMITTED', 'Borrowing', $request['id'], 'Borrowing request submitted for ' . $equipment['code']);
    }

    public static function approve(array &$state, string $requestId, string $adminId, string $status): void
    {
        $admin = self::user($state, $adminId);
        if (!$admin || $admin['role'] !== self::ADMIN) throw new RuntimeException('BR-A4-03: Only Administrator may approve or reject requests.');
        if (!in_array($status, ['Approved', 'Rejected'], true)) throw new RuntimeException('Invalid approval status.');
        foreach ($state['requests'] as &$request) {
            if ($request['id'] === $requestId) {
                if ($request['status'] !== 'Pending') throw new RuntimeException('Only pending requests can be changed.');
                $request['status'] = $status;
                $request['approvedBy'] = $adminId;
                self::audit($state, $adminId, strtoupper($status), 'Borrowing', $requestId, $status . ' borrowing request for ' . $request['equipmentId']);
                return;
            }
        }
        throw new RuntimeException('Request not found.');
    }

    public static function transition(array &$state, string $requestId, string $actorId, string $action, bool $damaged = false): void
    {
        $actor = self::user($state, $actorId);
        if (!$actor || !in_array($actor['role'], [self::ADMIN, self::STAFF], true)) throw new RuntimeException('Access denied: Administrator or Laboratory Staff only.');
        foreach ($state['requests'] as &$request) {
            if ($request['id'] !== $requestId) continue;
            $equipmentIndex = null;
            foreach ($state['equipment'] as $index => $equipment) if ($equipment['id'] === $request['equipmentId']) $equipmentIndex = $index;
            if ($equipmentIndex === null) throw new RuntimeException('Equipment not found.');
            if ($action === 'release') {
                if ($request['status'] !== 'Approved') throw new RuntimeException('BR-A4-04: Only Approved requests may be released.');
                $request['status'] = 'Released';
                $state['equipment'][$equipmentIndex]['status'] = 'Borrowed';
            } elseif ($action === 'return') {
                if (!in_array($request['status'], ['Released', 'Overdue'], true)) throw new RuntimeException('Only released requests can be returned.');
                $request['status'] = 'Returned';
                $state['equipment'][$equipmentIndex]['status'] = $damaged ? 'Damaged' : 'Available';
            } elseif ($action === 'close') {
                if ($request['status'] !== 'Returned') throw new RuntimeException('Only returned requests can be closed.');
                $request['status'] = 'Closed';
            } else {
                throw new RuntimeException('Unsupported action.');
            }
            self::audit($state, $actorId, strtoupper($action), 'Borrowing', $requestId, ucfirst($action) . ' equipment ' . $state['equipment'][$equipmentIndex]['code']);
            return;
        }
        throw new RuntimeException('Request not found.');
    }

    public static function maintenance(array &$state, string $equipmentId, string $userId, string $description): void
    {
        $user = self::user($state, $userId);
        if (!$user || !in_array($user['role'], [self::ADMIN, self::STAFF], true)) throw new RuntimeException('Access denied: Administrator or Laboratory Staff only.');
        if (trim($description) === '') throw new RuntimeException('Maintenance description is required.');
        $equipment = self::equipment($state, $equipmentId);
        if (!$equipment) throw new RuntimeException('Equipment not found.');
        foreach ($state['equipment'] as &$item) if ($item['id'] === $equipmentId) $item['status'] = 'Maintenance';
        $id = 'MT-' . bin2hex(random_bytes(4));
        $state['maintenance'][] = ['id' => $id, 'equipmentId' => $equipmentId, 'requesterId' => $userId, 'status' => 'Open', 'description' => $description];
        self::audit($state, $userId, 'MAINTENANCE_REQUESTED', 'Maintenance', $id, 'Maintenance requested for ' . $equipment['code']);
    }

    public static function addEquipment(array &$state, string $adminId, string $code, string $name, string $location): void
    {
        self::requireAdmin($state, $adminId);
        $code = trim($code);
        $name = trim($name);
        $location = trim($location);
        if ($code === '' || $name === '' || $location === '') throw new RuntimeException('Equipment code, name, and location are required.');
        foreach ($state['equipment'] as $item) if (strcasecmp($item['code'], $code) === 0) throw new RuntimeException('Equipment code already exists.');
        $id = 'EQ-' . bin2hex(random_bytes(4));
        $state['equipment'][] = ['id' => $id, 'code' => $code, 'name' => $name, 'status' => 'Available', 'location' => $location, 'condition' => 'Good'];
        self::audit($state, $adminId, 'EQUIPMENT_CREATED', 'Equipment', $id, 'Created equipment ' . $code);
    }

    public static function addUser(array &$state, string $adminId, string $name, string $role): void
    {
        self::requireAdmin($state, $adminId);
        if (!in_array($role, [self::ADMIN, self::STAFF, self::REQUESTER], true) || trim($name) === '') throw new RuntimeException('Valid user name and role are required.');
        $id = 'U-' . bin2hex(random_bytes(4));
        $state['users'][] = ['id' => $id, 'name' => trim($name), 'role' => $role];
        self::audit($state, $adminId, 'USER_CREATED', 'Users', $id, 'Created user ' . trim($name));
    }

    private static function requireAdmin(array $state, string $userId): void
    {
        $user = self::user($state, $userId);
        if (!$user || $user['role'] !== self::ADMIN) throw new RuntimeException('Access denied: Administrator only.');
    }

    private static function audit(array &$state, string $userId, string $action, string $module, string $recordId, string $description): void
    {
        array_unshift($state['auditLogs'], ['id' => 'AL-' . bin2hex(random_bytes(4)), 'userId' => $userId, 'action' => $action, 'module' => $module, 'recordId' => $recordId, 'description' => $description, 'createdAt' => date('c')]);
    }

    private static function ensureDataFile(): void
    {
        $directory = dirname(self::DATA_FILE);
        if (!is_dir($directory)) mkdir($directory, 0777, true);
        if (!file_exists(self::DATA_FILE)) file_put_contents(self::DATA_FILE, json_encode(self::initialState(), JSON_PRETTY_PRINT), LOCK_EX);
    }
}