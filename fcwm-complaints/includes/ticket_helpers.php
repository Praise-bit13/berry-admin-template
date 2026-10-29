<?php
declare(strict_types=1);

function db_department_name(int $departmentId): string
{
    $query = db()->prepare('SELECT name FROM departments WHERE id = :id');
    $query->execute(['id' => $departmentId]);
    $name = $query->fetchColumn();
    if ($name === false) {
        throw new RuntimeException('The selected department could not be found.');
    }
    return (string) $name;
}
