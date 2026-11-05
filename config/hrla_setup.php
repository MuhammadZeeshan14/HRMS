<?php

return [
    'shifts' => ['label' => 'Shifts', 'fields' => ['name' => 'Name', 'start_time' => 'Start time', 'end_time' => 'End time', 'overnight' => 'End date behavior']],
    'assignments' => ['label' => 'Shift assignments', 'fields' => ['employee' => 'Employee', 'shift' => 'Shift', 'starts_on' => 'Effective from', 'ends_on' => 'Effective through']],
    'holidays' => ['label' => 'Holidays', 'fields' => ['name' => 'Name', 'date' => 'Date (day/month)']],
    'types' => ['label' => 'Leave types', 'fields' => ['code' => 'Code', 'name' => 'Name', 'pay_treatment' => 'Pay treatment', 'description' => 'Description']],
    'policies' => ['label' => 'Leave policies', 'fields' => ['employeeStatus' => 'Employee status', 'leaveType' => 'Leave type', 'allowance_kind' => 'Allowance state', 'allowance' => 'Allowance', 'notes' => 'Notes']],
];
