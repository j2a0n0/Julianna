<style>
    .idea-room-page { color: var(--primary-font-color); }
    .idea-room-page .maincontentinner { max-width: 1500px; margin: 0 auto; }
    .idea-room-page h2 { color: var(--main-titles-color); font-size: 20px; margin: 0 0 8px; }
    .idea-room-page h3 { color: var(--main-titles-color); font-size: 15px; margin: 0; }
    .idea-room-page label { display: block; margin: 15px 0 6px; font-weight: 600; }
    .idea-room-page textarea, .idea-room-page input[type="text"], .idea-room-page select {
        box-sizing: border-box; width: 100%; max-width: none; border: 1px solid var(--main-border-color);
        border-radius: 7px; background: var(--secondary-background); color: var(--primary-font-color);
        padding: 10px 12px; resize: vertical;
    }
    .idea-room-page textarea:focus, .idea-room-page input:focus, .idea-room-page select:focus {
        outline: 2px solid var(--accent1); outline-offset: 2px;
    }
    .idea-room-lead { font-size: 16px; margin-bottom: 20px; }
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
    @media (max-width: 980px) {
        .idea-room-index-grid, .idea-room-columns { grid-template-columns: 1fr; }
        .idea-room-chat { min-height: 0; }
        .idea-room-messages { min-height: 220px; max-height: 45vh; }
    }
    @media (max-width: 600px) {
        .idea-room-card { padding: 15px; }
        .idea-room-room-list a { align-items: flex-start; flex-direction: column; gap: 5px; }
    }
</style>
