<?php
/**
 * SHEHITA EMS — What the AI assistant is allowed to look at
 *
 * The assistant can answer questions about a company's own records ("how many
 * unpaid invoices do we have?"). This file is the entire surface it can reach.
 *
 * Two rules hold everything together:
 *
 *  1. THE MODEL NEVER WRITES SQL. Each tool maps to one fixed, parameterised
 *     query. The model chooses which tool and supplies values; it cannot shape
 *     the query itself, so there is nothing for a prompt injection to steer.
 *
 *  2. EVERY TOOL RE-CHECKS THE USER'S PERMISSIONS. The model is trusted; the
 *     person typing at it is not. Without this, asking the assistant would be a
 *     way around the role permissions in the permissions table -- a clerk who
 *     cannot open the Invoices module could simply ask for the same numbers.
 *     canView() is the same function homepage.php gates modules with.
 *
 * Everything is read-only. There is no tool here that inserts, updates or
 * deletes, so the worst a confused model can do is decline to answer.
 */

/**
 * Tool definitions in the shape the Anthropic PHP SDK expects (camelCase
 * `inputSchema`; the SDK maps it to the wire format).
 */
function ai_tool_definitions(): array
{
    return [
        [
            'name'        => 'get_subscription_status',
            'description' => 'Get this company\'s SHEHITA subscription: whether it is a free trial '
                           . 'or a paid subscription, how many days remain, and the end date. '
                           . 'Use this for any question about billing, renewal, expiry or "how long do we have".',
            'inputSchema' => ['type' => 'object', 'properties' => (object)[], 'required' => []],
        ],
        [
            'name'        => 'count_records',
            'description' => 'Count the rows in one of this company\'s tables. Use for "how many X do we have".',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'entity' => [
                        'type' => 'string',
                        'enum' => ['customers', 'projects', 'invoices', 'operations', 'users', 'departments', 'roles'],
                        'description' => 'Which kind of record to count.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => 'Optional status filter, e.g. Active, Inactive, Paid, Unpaid, Expired.',
                    ],
                ],
                'required' => ['entity'],
            ],
        ],
        [
            'name'        => 'search_customers',
            'description' => 'Find customers by name, email or contact person.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Text to search for.'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum results (default 10, max 25).'],
                ],
                'required' => ['query'],
            ],
        ],
        [
            'name'        => 'list_invoices',
            'description' => 'List this company\'s invoices, optionally filtered by status or date range. '
                           . 'Use for questions about who owes money or what has been billed.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'status' => [
                        'type' => 'string',
                        'enum' => ['Paid', 'Unpaid', 'Partially Paid'],
                        'description' => 'Optional status filter.',
                    ],
                    'from'  => ['type' => 'string', 'description' => 'Optional start date, YYYY-MM-DD.'],
                    'to'    => ['type' => 'string', 'description' => 'Optional end date, YYYY-MM-DD.'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum results (default 10, max 25).'],
                ],
                'required' => [],
            ],
        ],
        [
            'name'        => 'financial_summary',
            'description' => 'Totals across this company\'s contracts and invoices: contract value, '
                           . 'profit, and how much has been invoiced versus paid.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'from' => ['type' => 'string', 'description' => 'Optional start date, YYYY-MM-DD.'],
                    'to'   => ['type' => 'string', 'description' => 'Optional end date, YYYY-MM-DD.'],
                ],
                'required' => [],
            ],
        ],
        [
            'name'        => 'explain_module',
            'description' => 'Explain what a part of the system is for and how to use it. '
                           . 'Use this for "how do I..." and "what does this page do" questions.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'module' => [
                        'type' => 'string',
                        'enum' => ['overview', 'customer-management', 'projects', 'operations', 'invoice',
                                   'status', 'user-management', 'roles', 'departments', 'permissions',
                                   'projectgroup', 'categories', 'company-settings', 'systemsettings',
                                   'profile', 'billing'],
                        'description' => 'Which module to explain.',
                    ],
                ],
                'required' => ['module'],
            ],
        ],
    ];
}

/**
 * Which module's permission governs each tool.
 * A tool with no entry here reads no company data and needs no permission.
 */
const AI_TOOL_PERMISSIONS = [
    'count_records'    => null,          // resolved per entity, see below
    'search_customers' => 'customer-management',
    'list_invoices'    => 'invoice',
    'financial_summary'=> 'overview',
];

const AI_ENTITY_PERMISSIONS = [
    'customers'   => 'customer-management',
    'projects'    => 'projects',
    'invoices'    => 'invoice',
    'operations'  => 'operations',
    'users'       => 'user-management',
    'departments' => 'departments',
    'roles'       => 'roles',
];

/**
 * Run one tool call and return the text handed back to the model.
 *
 * @param mysqli $conn   Connection to THIS company's database (never another's).
 * @param int    $roleId The signed-in user's role, for the permission re-check.
 */
function ai_run_tool(mysqli $conn, int $roleId, string $name, array $input): string
{
    switch ($name) {
        case 'get_subscription_status':
            return ai_tool_subscription();

        case 'count_records':
            return ai_tool_count($conn, $roleId, $input);

        case 'search_customers':
            return ai_tool_search_customers($conn, $roleId, $input);

        case 'list_invoices':
            return ai_tool_list_invoices($conn, $roleId, $input);

        case 'financial_summary':
            return ai_tool_financial_summary($conn, $roleId, $input);

        case 'explain_module':
            return ai_tool_explain($input);
    }

    return 'Unknown tool.';
}

/**
 * Can this user view this module?
 *
 * Wraps canView() from config.php rather than calling it directly, so that a
 * caller which forgot to load config.php DENIES rather than fatals — and, more
 * importantly, never falls through to returning the data. Getting this wrong in
 * the permissive direction would turn the assistant into a way around the
 * permissions table, so the missing-dependency case has to fail closed.
 */
function ai_can_view(mysqli $conn, int $roleId, string $module): bool
{
    if (!function_exists('canView')) {
        error_log('ai_tools: canView() unavailable (config.php not loaded); denying access to ' . $module);
        return false;
    }

    return (bool)canView($conn, $roleId, $module);
}

/**
 * The refusal the model sees when the user's role does not allow this data.
 * Phrased so the model relays a helpful sentence rather than inventing numbers.
 */
function ai_permission_denied(string $module): string
{
    return "PERMISSION DENIED. This user's role does not allow viewing the '{$module}' data, so "
         . "you must not answer this question with any figures. Tell them plainly that their "
         . "account does not have access to that information and suggest they ask their "
         . "company administrator.";
}

// ---------------------------------------------------------------------------
// Tools
// ---------------------------------------------------------------------------

function ai_tool_subscription(): string
{
    global $TENANT, $SUBSCRIPTION;

    if (!is_array($SUBSCRIPTION)) {
        return 'Subscription information is unavailable.';
    }

    $lines = [
        'Company: ' . ($TENANT['company_name'] ?? ''),
        'State: ' . $SUBSCRIPTION['status'],
        'Currently active: ' . ($SUBSCRIPTION['is_active'] ? 'yes' : 'no'),
        'Days remaining: ' . $SUBSCRIPTION['days_left'],
    ];

    if ($SUBSCRIPTION['ends_at'] !== null) {
        $lines[] = 'Ends on: ' . $SUBSCRIPTION['ends_at']->format('j F Y');
    }
    $lines[] = $SUBSCRIPTION['in_trial']
        ? 'This is the free trial, not a paid subscription.'
        : 'This is a paid subscription.';

    return implode("\n", $lines);
}

function ai_tool_count(mysqli $conn, int $roleId, array $input): string
{
    $entity = (string)($input['entity'] ?? '');
    $module = AI_ENTITY_PERMISSIONS[$entity] ?? null;

    if ($module === null) {
        return 'Unknown record type.';
    }
    if (!ai_can_view($conn, $roleId, $module)) {
        return ai_permission_denied($module);
    }

    // Table name comes from the whitelist above, never from the model's string.
    $table  = $entity;
    $status = trim((string)($input['status'] ?? ''));

    if ($status !== '') {
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM `{$table}` WHERE status = ?");
        $stmt->bind_param("s", $status);
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM `{$table}`");
    }

    if (!$stmt) {
        return 'That information is not available.';
    }

    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    return $status !== ''
        ? "{$entity} with status '{$status}': {$count}"
        : "Total {$entity}: {$count}";
}

function ai_tool_search_customers(mysqli $conn, int $roleId, array $input): string
{
    if (!ai_can_view($conn, $roleId, 'customer-management')) {
        return ai_permission_denied('customer-management');
    }

    $query = trim((string)($input['query'] ?? ''));
    $limit = min(25, max(1, (int)($input['limit'] ?? 10)));

    if ($query === '') {
        return 'No search text supplied.';
    }

    $like = '%' . $query . '%';
    $stmt = $conn->prepare(
        "SELECT customer_name, contact_person, email, type_of_business, status
           FROM customers
          WHERE customer_name LIKE ? OR email LIKE ? OR contact_person LIKE ?
          ORDER BY customer_name
          LIMIT ?"
    );
    $stmt->bind_param("sssi", $like, $like, $like, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($rows === []) {
        return "No customers matched '{$query}'.";
    }

    $out = "Customers matching '{$query}':\n";
    foreach ($rows as $r) {
        $out .= "- {$r['customer_name']} ({$r['status']})"
              . ($r['contact_person'] ? ", contact {$r['contact_person']}" : '')
              . ($r['email'] ? ", {$r['email']}" : '')
              . "\n";
    }

    return $out;
}

function ai_tool_list_invoices(mysqli $conn, int $roleId, array $input): string
{
    if (!ai_can_view($conn, $roleId, 'invoice')) {
        return ai_permission_denied('invoice');
    }

    $limit  = min(25, max(1, (int)($input['limit'] ?? 10)));
    $status = (string)($input['status'] ?? '');
    $from   = ai_valid_date((string)($input['from'] ?? ''));
    $to     = ai_valid_date((string)($input['to'] ?? ''));

    $where  = [];
    $params = [];
    $types  = '';

    if (in_array($status, ['Paid', 'Unpaid', 'Partially Paid'], true)) {
        $where[] = 'i.status = ?';
        $params[] = $status;
        $types .= 's';
    }
    if ($from !== null) { $where[] = 'i.invoice_date >= ?'; $params[] = $from; $types .= 's'; }
    if ($to !== null)   { $where[] = 'i.invoice_date <= ?'; $params[] = $to;   $types .= 's'; }

    $sql = "SELECT i.invoice_number, i.invoice_date, i.due_date, i.total, i.status, c.customer_name
              FROM invoices i
              LEFT JOIN customers c ON c.id = i.customer_id";
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY i.invoice_date DESC LIMIT ?';
    $params[] = $limit;
    $types   .= 'i';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($rows === []) {
        return 'No invoices matched those criteria.';
    }

    $out   = "Invoices:\n";
    $total = 0.0;
    foreach ($rows as $r) {
        $total += (float)$r['total'];
        $out .= "- {$r['invoice_number']} | {$r['customer_name']} | "
              . number_format((float)$r['total'], 2) . " | {$r['status']} | dated {$r['invoice_date']}\n";
    }
    $out .= 'Sum of the ' . count($rows) . ' invoices shown: ' . number_format($total, 2);

    return $out;
}

function ai_tool_financial_summary(mysqli $conn, int $roleId, array $input): string
{
    if (!ai_can_view($conn, $roleId, 'overview')) {
        return ai_permission_denied('overview');
    }

    $from = ai_valid_date((string)($input['from'] ?? ''));
    $to   = ai_valid_date((string)($input['to'] ?? ''));

    $out = "Financial summary:\n";

    // Contracts
    if ($from !== null && $to !== null) {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) n, COALESCE(SUM(contract_value),0) v, COALESCE(SUM(actual_profit),0) p
               FROM projects WHERE effective_date >= ? AND effective_date <= ?"
        );
        $stmt->bind_param("ss", $from, $to);
    } else {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) n, COALESCE(SUM(contract_value),0) v, COALESCE(SUM(actual_profit),0) p FROM projects"
        );
    }
    $stmt->execute();
    $p = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $out .= "- Contracts: {$p['n']}, total value " . number_format((float)$p['v'], 2)
          . ', actual profit ' . number_format((float)$p['p'], 2) . "\n";

    // Invoices, split by status
    $r = $conn->query(
        "SELECT status, COUNT(*) n, COALESCE(SUM(total),0) t FROM invoices GROUP BY status"
    );
    $invoiced = 0.0;
    $paid     = 0.0;
    while ($row = $r->fetch_assoc()) {
        $invoiced += (float)$row['t'];
        if ($row['status'] === 'Paid') {
            $paid += (float)$row['t'];
        }
        $out .= "- Invoices {$row['status']}: {$row['n']}, " . number_format((float)$row['t'], 2) . "\n";
    }
    $out .= '- Total invoiced ' . number_format($invoiced, 2)
          . ', of which paid ' . number_format($paid, 2)
          . ', outstanding ' . number_format($invoiced - $paid, 2);

    return $out;
}

/**
 * Static help text. Deliberately not in the database: this describes how the
 * software works, which is the same for every company, and keeping it in code
 * means it cannot be edited into something misleading.
 */
function ai_tool_explain(array $input): string
{
    $module = (string)($input['module'] ?? '');

    $help = [
        'overview' => 'Overview is the reporting dashboard: totals for contracts, profit and '
            . 'invoices, with filters by period. Open it from the top navigation.',
        'customer-management' => 'Customer Management is where clients are kept — name, contact '
            . 'person, TIN, VRN, address, email and business type. Add a customer here before '
            . 'you can raise a contract or an invoice for them.',
        'projects' => 'Projects holds contracts. Each has a contract number, a client, start and '
            . 'end dates, contract value, tax, commission, costs, staff numbers, and target vs '
            . 'actual profit. A contract automatically becomes Expired once its end date passes.',
        'operations' => 'Operations tracks the work delivered under a contract, grouped by '
            . 'project group and category. Work can be one-off or recurring (monthly, quarterly, '
            . 'semi-annually or annually), with assigned staff.',
        'invoice' => 'Invoices are billing documents for customers: particulars, quantity, rate, '
            . 'subtotal, VAT at 18%, and total, plus the bank details for payment. Status is '
            . 'Paid, Unpaid or Partially Paid, and you set it by hand. Print from the invoice '
            . 'preview to save a PDF.',
        'status' => 'Status shows contracts and their current state so you can see what is '
            . 'running, what has finished and what is about to expire.',
        'user-management' => 'User Management is for staff accounts: name, email, phone, photo, '
            . 'department and role. A user must be Active to sign in.',
        'roles' => 'Roles define job titles within a department. What each role can actually do '
            . 'is set in Permissions. Role 1, Super Admin, always has full access and cannot be removed.',
        'departments' => 'Departments group roles. Every role belongs to exactly one department.',
        'permissions' => 'Permissions decide what each role may do in each module — view, add, '
            . 'edit and delete, ticked separately. Super Admin bypasses this entirely.',
        'projectgroup' => 'Project Groups are the top level of the work hierarchy; categories sit '
            . 'underneath them and operations are recorded against a group and category.',
        'categories' => 'Categories sit under a project group and classify the kind of work done.',
        'company-settings' => 'Company Settings holds the company profile printed on invoices and '
            . 'reports: name, address, email, phone, TIN, VRN, currency and logo.',
        'systemsettings' => 'System Settings covers application-wide preferences such as language, '
            . 'timezone, date format, rows per page and session timeout.',
        'profile' => 'Profile is your own account: name, phone, address, photo and password.',
        'billing' => 'Billing shows this company\'s SHEHITA subscription — whether it is a trial '
            . 'or paid, when it ends, the available packages, and the form for telling SHEHITA '
            . 'you have paid. Only the company administrator can open it.',
    ];

    return $help[$module] ?? 'No description available for that part of the system.';
}

/** Accept only a real YYYY-MM-DD date; anything else is treated as absent. */
function ai_valid_date(string $value): ?string
{
    if ($value === '') {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $value);

    return ($d !== false && $d->format('Y-m-d') === $value) ? $value : null;
}
