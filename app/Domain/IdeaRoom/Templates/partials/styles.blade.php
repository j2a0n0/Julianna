<style>
    .idea-room-page { color: var(--primary-font-color); }
    .idea-room-page .maincontentinner { max-width: 1500px; margin: 0 auto; }
    .idea-room-page h2 { color: var(--main-titles-color); font-size: 20px; margin: 0 0 8px; }
    .idea-room-page h3 { color: var(--main-titles-color); font-size: 15px; margin: 0; }
    .idea-room-page label { display: block; margin: 15px 0 6px; font-weight: 600; }
    .idea-room-page textarea, .idea-room-page input[type="text"], .idea-room-page input[type="password"], .idea-room-page select {
        box-sizing: border-box; width: 100%; max-width: none; border: 1px solid var(--main-border-color);
        border-radius: 7px; background: var(--secondary-background); color: var(--primary-font-color);
        padding: 10px 12px; resize: vertical;
    }
    .idea-room-page textarea:focus, .idea-room-page input:focus, .idea-room-page select:focus {
        outline: 2px solid var(--accent1); outline-offset: 2px;
    }
    .idea-room-lead { font-size: 16px; margin-bottom: 20px; }
    .idea-room-testing-ai { margin: 0 0 18px; padding: 15px 18px; border: 1px solid var(--main-border-color); border-radius: 9px; background: var(--secondary-background); }
    .idea-room-testing-ai summary { cursor: pointer; font-weight: 700; }
    .idea-room-testing-ai p { margin: 10px 0; font-size: 13px; }
    .idea-room-testing-ai-form { display: grid; grid-template-columns: repeat(3, minmax(150px, 1fr)); gap: 10px 15px; align-items: end; }
    .idea-room-testing-ai-form label { margin-top: 5px; }
    .idea-room-testing-ai-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .idea-room-testing-ai-form .idea-room-feedback { grid-column: 1 / -1; }
    @media (max-width: 760px) { .idea-room-testing-ai-form { grid-template-columns: 1fr; } }
    .idea-room-index-grid, .idea-room-columns { display: grid; gap: 22px; align-items: start; }
    .idea-room-index-grid { grid-template-columns: minmax(280px, 2fr) minmax(280px, 1fr); }
    .idea-room-columns { grid-template-columns: minmax(340px, 1fr) minmax(360px, 1fr); }
    .idea-room-card { min-width: 0; background: var(--secondary-background); border: 1px solid var(--main-border-color); border-radius: 12px; padding: 22px; }
    .idea-room-card > p, .idea-room-panel-heading p { opacity: .78; margin: 0 0 12px; }
    .idea-room-field-note { font-size: 13px; opacity: .78; margin: 9px 0 14px; }
    .idea-room-room-list { list-style: none; margin: 0; padding: 0; }
    .idea-room-room-list li + li { border-top: 1px solid var(--main-border-color); }
    .idea-room-room-list a { display: flex; justify-content: space-between; gap: 14px; padding: 13px 0; align-items: center; }
    .idea-room-room-name { font-weight: 600; overflow-wrap: anywhere; }
    .idea-room-empty { opacity: .68; padding: 12px 0; }
    .idea-room-status { display: inline-block; border: 1px solid var(--main-border-color); border-radius: 999px; padding: 4px 10px; font-size: 12px; white-space: nowrap; }
    .idea-room-status--ready_for_review { border-color: var(--accent1); color: var(--accent1); }
    .idea-room-status--approved { border-color: var(--accent2); color: var(--accent2); }
    .idea-room-topline { display: flex; justify-content: space-between; gap: 10px; align-items: center; margin-bottom: 16px; }
    .idea-room-panel-heading { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
    .idea-room-chat { display: flex; flex-direction: column; min-height: 580px; }
    .idea-room-messages { flex: 1; min-height: 300px; max-height: 65vh; overflow-y: auto; padding: 8px 4px 16px 0; }
    .idea-room-message { max-width: 90%; margin: 12px 0; padding: 12px 14px; border-radius: 12px; background: var(--layered-background); border: 1px solid var(--main-border-color); }
    .idea-room-message--user { margin-left: auto; background: var(--secondary-background); border-color: var(--accent1); }
    .idea-room-message-author { display: block; font-weight: 700; font-size: 12px; margin-bottom: 5px; }
    .idea-room-message p { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; }
    .idea-room-compose { border-top: 1px solid var(--main-border-color); padding-top: 12px; }
    .idea-room-compose-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 10px; }
    .idea-room-compose-footer span { font-size: 12px; opacity: .7; }
    .idea-room-target { padding: 9px 12px; background: var(--layered-background); border-radius: 7px; }
    .idea-room-plan-section { border-top: 1px solid var(--main-border-color); padding-top: 16px; margin-top: 20px; }
    .idea-room-section-heading { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 10px; }
    .idea-room-text-button { background: none; border: 0; padding: 3px; color: var(--accent1); cursor: pointer; }
    .idea-room-text-button:hover { text-decoration: underline; }
    .idea-room-edit-item { border: 1px solid var(--main-border-color); border-radius: 8px; padding: 12px; margin: 10px 0; }
    .idea-room-edit-item .idea-room-edit-item { margin-left: 12px; }
    .idea-room-edit-item-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .idea-room-edit-item-head strong { font-size: 13px; }
    .idea-room-edit-item label { font-size: 12px; margin-top: 10px; }
    .idea-room-edit-item textarea { min-height: 65px; }
    .idea-room-edit-item input[type="text"] { min-height: 38px; }
    .idea-room-review { border-top: 1px solid var(--main-border-color); margin-top: 20px; padding-top: 16px; }
    .idea-room-review .btn { margin: 8px 8px 0 0; }
    .idea-room-feedback { margin: 14px 0 0; padding: 10px 12px; border-radius: 6px; background: var(--layered-background); }
    .idea-room-feedback.is-error { border: 1px solid #bd3550; }
    .idea-room-layout { display: grid; grid-template-columns: minmax(330px, 1.2fr) minmax(320px, .95fr) minmax(285px, 340px); gap: 14px; align-items: stretch; min-height: min(760px, calc(100vh - 195px)); }
    .idea-room-layout > .idea-room-card { min-height: 0; }
    .idea-room-layout .idea-room-chat { min-height: 0; max-height: calc(100vh - 185px); padding-bottom: 0; }
    .idea-room-chat-heading { align-items: start; flex: 0 0 auto; }
    .idea-room-presence { color: var(--accent1); font-size: 12px; white-space: nowrap; }
    .idea-room-layout .idea-room-messages { min-height: 220px; max-height: none; overflow-y: auto; overscroll-behavior: contain; }
    .idea-room-message-body p + p { margin-top: 9px; }
    .idea-room-message-body ul { margin: 8px 0; padding-left: 23px; }
    .idea-room-message-body li + li { margin-top: 5px; }
    .idea-room-message-body pre { max-width: 100%; overflow-x: auto; padding: 10px; border-radius: 6px; background: var(--secondary-background); }
    .idea-room-message-body a { color: var(--accent1); text-decoration: underline; }
    .idea-room-compose { position: sticky; bottom: 0; z-index: 2; background: var(--secondary-background); padding: 12px 0 18px; flex: 0 0 auto; }
    .idea-room-compose textarea { min-height: 72px; max-height: 210px; }
    .idea-room-compose-actions { display: flex; gap: 7px; flex-wrap: wrap; justify-content: flex-end; }
    .idea-room-stream-state { padding: 5px 2px; color: var(--accent1); font-size: 12px; }
    .idea-room-chat-error { display: flex; align-items: center; justify-content: space-between; gap: 9px; padding: 9px 12px; border-radius: 7px; border: 1px solid #bd3550; }
    .idea-room-chat-error[hidden], .idea-room-stream-state[hidden] { display: none; }
    .idea-room-sidebar { max-height: calc(100vh - 185px); overflow-y: auto; }
    .idea-room-sidebar-content { display: grid; gap: 18px; }
    .idea-room-sidebar-section { border-top: 1px solid var(--main-border-color); padding-top: 15px; }
    .idea-room-sidebar-section:first-child { border-top: 0; padding-top: 0; }
    .idea-room-sidebar-section h3 { margin-bottom: 9px; }
    .idea-room-sidebar-section select { margin-top: 2px; }
    .idea-room-summary-group + .idea-room-summary-group { margin-top: 11px; }
    .idea-room-summary-group h4 { margin: 0 0 4px; font-size: 12px; font-weight: 700; }
    .idea-room-summary-group p, .idea-room-summary-group ul { margin: 0; font-size: 13px; overflow-wrap: anywhere; }
    .idea-room-summary-group ul { padding-left: 20px; }
    .idea-room-muted { opacity: .67; }
    .idea-room-legacy-plan { border-top: 1px solid var(--main-border-color); padding-top: 15px; }
    .idea-room-legacy-plan summary { cursor: pointer; font-weight: 600; }
    .idea-room-archive { justify-self: start; }
    .idea-room-activity-card { max-width: 92%; margin: 10px 0; padding: 10px 12px; border: 1px solid var(--main-border-color); border-radius: 9px; background: var(--layered-background); font-size: 13px; }
    .idea-room-activity-card--pending { max-width: 100%; border-color: var(--accent1); }
    .idea-room-activity-card--destructive { border-color: #bd3550; }
    .idea-room-activity-warning { color: #bd3550; font-weight: 600; margin: 8px 0 0; }
    .idea-room-activity-heading { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; }
    .idea-room-activity-status, .idea-room-tool-name { opacity: .75; font-size: 11px; }
    .idea-room-activity-args { display: grid; grid-template-columns: auto 1fr; gap: 3px 8px; margin: 9px 0; }
    .idea-room-activity-args dt { font-weight: 700; }
    .idea-room-activity-args dd { margin: 0; overflow-wrap: anywhere; }
    .idea-room-activity-result { margin: 8px 0 0; white-space: pre-wrap; overflow-wrap: anywhere; }
    .idea-room-confirm-actions { display: flex; gap: 7px; margin-top: 10px; }
    .idea-room-confirm-actions .btn { min-width: 0; }
    .idea-room-modebar { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin: 0 0 14px; }
    .idea-room-modebar > span { font-size: 12px; font-weight: 700; margin-right: 4px; }
    .idea-room-modebar small { opacity: .75; margin-left: 6px; }
    .idea-room-mode-button { border: 1px solid var(--main-border-color); border-radius: 999px; background: var(--secondary-background); color: var(--primary-font-color); padding: 6px 13px; cursor: pointer; }
    .idea-room-mode-button[aria-pressed="true"] { background: var(--accent1); border-color: var(--accent1); color: #fff; }
    .idea-room-canvas-panel { display: flex; flex-direction: column; min-height: 0; overflow: hidden; padding: 14px; }
    .idea-room-canvas-panel .idea-room-panel-heading { padding: 4px 6px 0; }
    .idea-room-graph-version { font-size: 11px; opacity: .65; white-space: nowrap; }
    .idea-room-canvas-tools { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; padding-bottom: 10px; }
    .idea-room-canvas-tools .btn { padding: 5px 9px; font-size: 12px; }
    .idea-room-canvas-tools select { width: auto; max-width: 126px; min-width: 95px; padding: 5px 7px; font-size: 12px; }
    .idea-room-canvas-tools .is-active { color: var(--accent1); outline: 2px solid var(--accent1); }
    .idea-room-canvas-tools #idea-room-zoom-label { min-width: 36px; text-align: center; font-size: 11px; }
    .idea-room-canvas-viewport { position: relative; flex: 1; min-height: 400px; overflow: hidden; border: 1px solid var(--main-border-color); border-radius: 10px; background-color: var(--layered-background); background-image: radial-gradient(var(--main-border-color) 1px, transparent 1px); background-size: 24px 24px; cursor: grab; touch-action: none; }
    .idea-room-canvas-viewport:active { cursor: grabbing; }
    .idea-room-canvas-viewport:focus-visible { outline: 2px solid var(--accent1); outline-offset: 2px; }
    .idea-room-canvas-world { position: absolute; top: 0; left: 0; width: 1px; height: 1px; transform-origin: 0 0; }
    .idea-room-canvas-links { position: absolute; left: 0; top: 0; width: 1px; height: 1px; overflow: visible; pointer-events: none; }
    .idea-room-canvas-links line { stroke: var(--accent1); stroke-width: 2; opacity: .68; }
    .idea-room-canvas-links text { fill: var(--primary-font-color); font-size: 11px; paint-order: stroke; stroke: var(--layered-background); stroke-width: 4px; }
    .idea-room-canvas-nodes { position: absolute; top: 0; left: 0; }
    .idea-room-canvas-node { position: absolute; width: 184px; min-height: 75px; padding: 10px 11px; border: 1px solid var(--main-border-color); border-left: 4px solid var(--accent1); border-radius: 8px; background: var(--secondary-background); box-shadow: 0 3px 12px rgba(0,0,0,.12); color: var(--primary-font-color); cursor: pointer; text-align: left; user-select: none; overflow: hidden; }
    .idea-room-canvas-node strong { display: block; font-size: 13px; line-height: 1.3; overflow-wrap: anywhere; }
    .idea-room-canvas-node small { display: block; font-size: 10px; opacity: .68; margin-bottom: 3px; }
    .idea-room-canvas-node p { margin: 5px 0 0; font-size: 11px; line-height: 1.3; opacity: .78; overflow-wrap: anywhere; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .idea-room-canvas-node[data-type="question"] { border-left-color: #d39a2d; }
    .idea-room-canvas-node[data-type="insight"] { border-left-color: #48a789; }
    .idea-room-canvas-node[data-type="source"] { border-left-color: #667ab9; }
    .idea-room-canvas-node[data-type="decision"] { border-left-color: #b678a9; }
    .idea-room-canvas-node[data-type="next_step"] { border-left-color: #d0796a; }
    .idea-room-canvas-node.is-selected { outline: 3px solid var(--accent1); outline-offset: 2px; z-index: 2; }
    .idea-room-canvas-node.is-connect-source { outline: 3px dashed var(--accent1); outline-offset: 3px; }
    .idea-room-canvas-empty { position: absolute; top: 45%; left: 50%; transform: translate(-50%,-50%); width: min(280px,80%); text-align: center; opacity: .68; pointer-events: none; }
    .idea-room-canvas-status { min-height: 18px; margin: 7px 4px 0; font-size: 12px; opacity: .8; }
    .idea-room-canvas-status.is-error { color: #bd3550; opacity: 1; }
    .idea-room-recoverable-nodes { margin: 4px 4px 0; font-size: 12px; }
    .idea-room-recoverable-nodes summary { cursor: pointer; color: var(--accent1); }
    .idea-room-recoverable-nodes details[open] { max-height: 180px; overflow-y: auto; }
    .idea-room-recoverable-node { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 4px 0; }
    .idea-room-recoverable-node span { min-width: 0; overflow-wrap: anywhere; }
    .idea-room-node-actions { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 10px; }
    .idea-room-inspector-link { display: flex; align-items: center; justify-content: space-between; gap: 6px; padding: 5px 0; border-bottom: 1px solid var(--main-border-color); font-size: 12px; }
    .idea-room-inspector-link span { overflow-wrap: anywhere; }
    .idea-room-research-card, .idea-room-history-card { border: 1px solid var(--main-border-color); border-radius: 8px; padding: 10px; margin-top: 10px; background: var(--layered-background); overflow-wrap: anywhere; }
    .idea-room-research-card strong, .idea-room-history-card strong { display: block; font-size: 13px; }
    .idea-room-history-card.is-current { border-color: var(--accent1); }
    .idea-room-history-heading { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; }
    .idea-room-history-origin { flex: 0 0 auto; border: 1px solid var(--main-border-color); border-radius: 999px; padding: 2px 7px; font-size: 10px; }
    .idea-room-history-card time { display: block; margin-top: 4px; font-size: 11px; opacity: .7; }
    .idea-room-history-card .idea-room-history-summary { font-weight: 600; }
    .idea-room-history-card .btn { margin-top: 8px; padding: 5px 9px; font-size: 12px; }
    .idea-room-history-feedback.is-error { color: #bd3550; }
    .idea-room-research-card small { display: block; opacity: .68; font-size: 11px; margin: 3px 0; }
    .idea-room-research-card p, .idea-room-history-card p { margin: 5px 0; font-size: 12px; }
    .idea-room-research-card-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
    .idea-room-research-card-actions a { color: var(--accent1); }
    .idea-room-research-suggestion { display: flex; gap: 8px; align-items: center; margin: 8px 0; padding: 8px; border-radius: 7px; background: var(--layered-background); }
    .idea-room-research-suggestion span { flex: 1; overflow-wrap: anywhere; }
    .idea-room-page[data-mode="execute"] .idea-room-canvas-panel { opacity: .88; }
    .idea-room-page[data-mode="execute"] .idea-room-layout { grid-template-columns: minmax(300px, .8fr) minmax(350px, 1.1fr) minmax(310px, 360px); }
    .idea-room-page[data-mode="execute"] .idea-room-sidebar-content { display: flex; flex-direction: column; }
    .idea-room-page[data-mode="execute"] .idea-room-sidebar-section[aria-labelledby="idea-room-summary-heading"] { order: -2; }
    .idea-room-page[data-mode="execute"] .idea-room-sidebar-section[aria-labelledby="idea-room-pending-heading"] { order: -1; }
    @media (max-width: 1250px) {
        .idea-room-layout { grid-template-columns: minmax(320px, 1fr) minmax(320px, 1fr); }
        .idea-room-page[data-mode="execute"] .idea-room-layout { grid-template-columns: minmax(320px, 1fr) minmax(320px, 1fr); }
        .idea-room-sidebar { grid-column: 1 / -1; max-height: none; }
        .idea-room-sidebar-content { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 980px) {
        .idea-room-index-grid, .idea-room-columns, .idea-room-layout { grid-template-columns: 1fr; }
        .idea-room-page[data-mode="execute"] .idea-room-layout { grid-template-columns: 1fr; }
        .idea-room-layout { min-height: 0; }
        .idea-room-layout .idea-room-chat { min-height: 500px; max-height: none; }
        .idea-room-layout .idea-room-messages { max-height: 50vh; }
        .idea-room-sidebar { max-height: none; grid-column: auto; }
        .idea-room-sidebar-content { grid-template-columns: 1fr; }
        .idea-room-canvas-viewport { min-height: 470px; }
    }
    @media (max-width: 600px) {
        .idea-room-card { padding: 15px; }
        .idea-room-room-list a { align-items: flex-start; flex-direction: column; gap: 5px; }
    }
</style>
