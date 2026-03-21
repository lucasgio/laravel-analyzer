<?php

declare(strict_types=1);

namespace LaravelAnalyzer\Mcp;

/**
 * MCP Server — JSON-RPC 2.0 over stdio.
 *
 * Implements the Model Context Protocol (2024-11-05) spec:
 * - STDOUT: JSON-RPC messages only (newline-delimited)
 * - STDIN:  incoming JSON-RPC messages
 * - STDERR: logging / debug output
 *
 * Compatible with: Claude Code, Cursor, Windsurf, Gemini CLI,
 * VS Code (Copilot MCP extension), and any MCP-compliant client.
 */
class Server
{
    private const PROTOCOL_VERSION = '2024-11-05';
    private const SERVER_NAME      = 'laravel-analyzer';
    private const SERVER_VERSION   = '1.0.0';

    private ToolHandler     $toolHandler;
    private ResourceHandler $resourceHandler;
    private PromptHandler   $promptHandler;

    private bool $initialized = false;

    public function __construct(string $projectPath)
    {
        $this->toolHandler     = new ToolHandler($projectPath);
        $this->resourceHandler = new ResourceHandler();
        $this->promptHandler   = new PromptHandler($projectPath);
    }

    // ─────────────────────────────────────────
    // MAIN LOOP
    // ─────────────────────────────────────────

    public function run(): never
    {
        $this->log("Laravel Analyzer MCP Server started (PID " . getmypid() . ")");

        while (($line = fgets(STDIN)) !== false) {
            $line = trim($line);
            if ($line === '') continue;

            $message = json_decode($line, true);

            if ($message === null) {
                $this->log("Invalid JSON received: {$line}");
                continue;
            }

            $response = $this->dispatch($message);

            if ($response !== null) {
                $this->send($response);
            }
        }

        $this->log("MCP Server shutting down.");
        exit(0);
    }

    // ─────────────────────────────────────────
    // DISPATCHER
    // ─────────────────────────────────────────

    private function dispatch(array $message): ?array
    {
        $id     = $message['id']     ?? null;
        $method = $message['method'] ?? '';
        $params = $message['params'] ?? [];

        // Notifications have no id and expect no response
        if (!array_key_exists('id', $message)) {
            $this->handleNotification($method, $params);
            return null;
        }

        try {
            $result = $this->route($method, $params);
            return $this->success($id, $result);
        } catch (\Throwable $e) {
            $this->log("Error in {$method}: " . $e->getMessage());
            return $this->error($id, $e->getCode() ?: -32000, $e->getMessage());
        }
    }

    private function route(string $method, array $params): mixed
    {
        return match ($method) {
            'initialize'     => $this->handleInitialize($params),
            'ping'           => [],
            'tools/list'     => $this->toolHandler->list(),
            'tools/call'     => $this->toolHandler->call($params),
            'resources/list' => $this->resourceHandler->list(),
            'resources/read' => $this->resourceHandler->read($params),
            'prompts/list'   => $this->promptHandler->list(),
            'prompts/get'    => $this->promptHandler->get($params),
            default          => throw new \RuntimeException(
                "Method not found: {$method}", -32601
            ),
        };
    }

    private function handleNotification(string $method, array $params): void
    {
        if ($method === 'notifications/initialized') {
            $this->initialized = true;
            $this->log("Client initialized successfully.");
        }
    }

    // ─────────────────────────────────────────
    // INITIALIZE HANDSHAKE
    // ─────────────────────────────────────────

    private function handleInitialize(array $params): array
    {
        $clientName    = $params['clientInfo']['name']    ?? 'unknown';
        $clientVersion = $params['clientInfo']['version'] ?? '?';
        $this->log("Initialize request from {$clientName} v{$clientVersion}");

        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities'    => [
                'tools'     => ['listChanged' => false],
                'resources' => ['listChanged' => false, 'subscribe' => false],
                'prompts'   => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name'    => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
            'instructions' => implode("\n", [
                "Laravel Best Practices Analyzer — static analysis for Laravel projects.",
                "Use the 'analyze' tool to run a full project scan and get scores, issues, and recommendations.",
                "Use 'analyze_module' to focus on a specific area (security, owasp, coupling, etc.).",
                "Use 'get_issues' and 'get_recommendations' for targeted queries.",
                "All tools accept an optional 'project_path' argument; defaults to the configured project root.",
            ]),
        ];
    }

    // ─────────────────────────────────────────
    // TRANSPORT HELPERS
    // ─────────────────────────────────────────

    private function send(array $message): void
    {
        fwrite(STDOUT, json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        fflush(STDOUT);
    }

    private function success(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => ['code' => $code, 'message' => $message],
        ];
    }

    private function log(string $message): void
    {
        fwrite(STDERR, "[laravel-analyzer] {$message}\n");
    }
}
