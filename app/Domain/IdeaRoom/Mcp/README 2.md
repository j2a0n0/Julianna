# Idea Room tools on the existing MCP server

The private `McpServer` plugin owns Julianna's authenticated `/mcp` route and its
existing tool catalog. In that plugin's `LeantimeMcpServer::boot()` method, after
its existing discovery/registration, add:

```php
\Leantime\Domain\IdeaRoom\Mcp\IdeaRoomMcpToolProvider::register($this);
```

`register()` calls the installed Laravel MCP server's `addTool()` method for all
23 Idea Room tools. It does not add another route or authentication system. The
public application can boot without the private plugin; in that checkout `/mcp`
is unavailable, so its HTTP acceptance tests skip. The provider catalog and
result shape have public unit tests.
