# Retired Idea Room MCP tools

Idea Room is a read-only archive. `IdeaRoomMcpToolProvider::register()` is a
compatibility no-op so an older optional MCP plugin cannot register its former
chat, proposal, approval, or canvas-mutation tools. The first-party `/mcp`
endpoint and in-app agent both use `Leantime\Domain\Mcp\Services\ToolCatalog`.

Do not restore archived Idea Room mutations during deployment. Use the
first-party Whiteboard tools for new visual work.
