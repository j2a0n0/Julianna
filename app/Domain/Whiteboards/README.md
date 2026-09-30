# Julianna Whiteboards

This is Julianna's first-party, project-scoped Excalidraw 0.18 integration. It
does not contain or depend on the Leantime commercial Whiteboards plugin.

`Whiteboards` is the shared service for the browser, agent, and MCP catalog.
Every public method takes an explicit actor ID and checks that actor's current
account state, project access, and `whiteboards.*` permission. Scene writes and
restores require the board's expected revision. A conflict returns HTTP 409 in
the browser and must be retried from the current board state. Each successful
change appends an immutable revision; restoring a prior revision creates a new
revision rather than deleting history.

For this release, Whiteboard MCP tools support authenticated human principals
using a personal Bearer token. Legacy `x-api-key` principals are intentionally
denied: they have no Julianna human account, and must not inherit new Whiteboard
rights merely because their `zp_user` row is active. The rest of the MCP
catalog retains its existing credential behavior.

The browser editor is a separate React island. Its JavaScript, CSS, fonts, and
lazy-loaded locale chunks are built from pinned npm dependencies and served
locally under `/dist/`. Swiss French is mapped to Excalidraw's `fr-FR` locale.
Scenes accept only local raster image data URLs; cloud embeds, iframes, and
magic frames are rejected. This release saves project-shared scenes and assets,
but does not offer live co-editing.
