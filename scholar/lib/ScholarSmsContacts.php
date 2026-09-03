<?php
declare(strict_types=1);

/**
 * Resolves an sms_contact_groups row into an actual [name, phone] list at
 * send time. 'class'/'whole_school' groups are never snapshotted --
 * always freshly queried, so a phone number change or a newly-added
 * guardian is picked up automatically on the next send.
 *
 * Unifies the two contact sources that exist in Scholar today and were
 * never cross-referenced before this module: `users` (role='parent')
 * joined through `parent_students` -- the only source
 * message_parents.php reads -- AND `guardians`, a separate simpler
 * per-student contact record nothing sends to today. A family recorded
 * in only one of the two is still reachable here.
 */
final class ScholarSmsContacts
{
    /** @return array<int, array{full_name: ?string, phone: string}> */
    public static function resolveGroup(PDO $pdo, int $schoolId, array $group): array
    {
        return match ($group['group_type']) {
            'class' => self::resolveClass($pdo, $schoolId, (int) $group['class_id']),
            'whole_school' => self::resolveWholeSchool($pdo, $schoolId),
            default => self::resolveImported($pdo, (int) $group['id']),
        };
    }

    /** @return array<int, array{full_name: ?string, phone: string}> */
    public static function resolveClass(PDO $pdo, int $schoolId, int $classId): array
    {
        return self::dedupe(array_merge(
            self::fromParentLogins($pdo, $schoolId, $classId),
            self::fromGuardians($pdo, $schoolId, $classId)
        ));
    }

    /** @return array<int, array{full_name: ?string, phone: string}> */
    public static function resolveWholeSchool(PDO $pdo, int $schoolId): array
    {
        return self::dedupe(array_merge(
            self::fromParentLogins($pdo, $schoolId, null),
            self::fromGuardians($pdo, $schoolId, null)
        ));
    }

    /** @return array<int, array{full_name: ?string, phone: string}> */
    public static function resolveImported(PDO $pdo, int $groupId): array
    {
        $stmt = $pdo->prepare("SELECT full_name, phone FROM sms_contacts WHERE group_id = ?");
        $stmt->execute([$groupId]);
        return self::dedupe($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Same source/shape as message_parents.php's existing query. */
    private static function fromParentLogins(PDO $pdo, int $schoolId, ?int $classId): array
    {
        $sql = "
            SELECT DISTINCT s.full_name, u.phone_number AS phone
            FROM users u
            JOIN parent_students ps ON ps.user_id = u.id
            JOIN students s ON s.id = ps.student_id
            WHERE u.school_id = ? AND u.role = 'parent'
                  AND u.phone_number IS NOT NULL AND u.phone_number <> ''
        ";
        $params = [$schoolId];
        if ($classId !== null) {
            $sql .= " AND s.class_id = ?";
            $params[] = $classId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** The previously-unused source -- guardians recorded with no parent login account. */
    private static function fromGuardians(PDO $pdo, int $schoolId, ?int $classId): array
    {
        $sql = "
            SELECT DISTINCT g.guardian_name AS full_name, g.phone AS phone
            FROM guardians g
            JOIN students s ON s.id = g.student_id
            WHERE g.school_id = ? AND g.phone IS NOT NULL AND g.phone <> ''
        ";
        $params = [$schoolId];
        if ($classId !== null) {
            $sql .= " AND s.class_id = ?";
            $params[] = $classId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Same phone number from both sources (or duplicated some other way) counts once. */
    private static function dedupe(array $rows): array
    {
        $byPhone = [];
        foreach ($rows as $row) {
            $phone = trim((string) $row['phone']);
            if ($phone === '') {
                continue;
            }
            if (!isset($byPhone[$phone])) {
                $byPhone[$phone] = ['full_name' => $row['full_name'] ?? null, 'phone' => $phone];
            }
        }
        return array_values($byPhone);
    }
}
