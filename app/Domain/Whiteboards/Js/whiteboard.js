import React from 'react';
import { createRoot } from 'react-dom/client';
import { Excalidraw } from '@excalidraw/excalidraw';

// Excalidraw CSS and fonts are copied to /dist/excalidraw and served by
// Julianna. This island never calls Excalidraw's cloud backend.
// Mix's global resourceRoot defaults to '/', which would otherwise make
// Excalidraw's lazy French-locale chunk request /js/... instead of /dist/js/...
__webpack_public_path__ = window.JULIANNA_WHITEBOARD_ASSET_BASE || '/dist/';
const host = document.getElementById('julianna-whiteboard-editor');

if (host) {
    const status = document.getElementById('whiteboard-save-status');
    const revisionLabel = document.getElementById('whiteboard-revision');
    const saveButton = document.getElementById('whiteboard-save');
    const restoreButton = document.getElementById('whiteboard-restore');
    const history = document.getElementById('whiteboard-history');
    const readOnly = host.dataset.readOnly === '1';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    let currentRevision = 0;
    let savedSignature = '';
    let latestScene = null;
    let saveTimer = null;
    let saving = false;
    let blockedByConflict = false;
    let acceptingChanges = false;

    const displayStatus = (text, isError = false) => {
        status.textContent = text;
        status.style.color = isError ? '#bd3550' : '';
    };

    const sceneForSave = (elements, appState, files) => ({
        // Excalidraw may expose a transient selection box during a drag. It is
        // UI state, not a durable shared scene element.
        elements: Array.from(elements || []).filter((element) => element.type !== 'selection'),
        appState: { viewBackgroundColor: appState?.viewBackgroundColor || '#ffffff' },
        files: files || {},
    });

    const signature = (scene) => JSON.stringify(scene);

    const request = async (url, method, body) => {
        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        const data = await response.json();
        if (!response.ok) {
            const error = new Error(data.error || host.dataset.labelFailed);
            error.status = response.status;
            throw error;
        }
        return data;
    };

    const updateRevision = (revision) => {
        currentRevision = revision;
        revisionLabel.textContent = `${host.dataset.labelRevision} ${revision}`;
    };

    const refreshHistory = async () => {
        const data = await request(host.dataset.revisionsUrl, 'GET');
        const first = history.options[0];
        history.replaceChildren(first);
        (data.revisions || []).forEach((item) => {
            const option = document.createElement('option');
            option.value = item.revision;
            option.textContent = `${host.dataset.labelRevision} ${item.revision} · ${item.created_at}`;
            history.append(option);
        });
    };

    const save = async () => {
        if (readOnly || saving || blockedByConflict || !latestScene || signature(latestScene) === savedSignature) return;
        if (saveTimer) clearTimeout(saveTimer);
        saving = true;
        displayStatus(host.dataset.labelSaving);
        const snapshot = latestScene;
        const sentSignature = signature(snapshot);
        try {
            const board = await request(host.dataset.boardUrl, 'PUT', {
                expectedRevision: currentRevision,
                scene: snapshot,
            });
            updateRevision(board.revision);
            savedSignature = sentSignature;
            displayStatus(signature(latestScene) === savedSignature ? host.dataset.labelSaved : host.dataset.labelUnsaved);
            refreshHistory().catch(() => {});
        } catch (error) {
            if (error.status === 409) {
                blockedByConflict = true;
                displayStatus(host.dataset.labelConflict, true);
            } else {
                displayStatus(host.dataset.locale === 'fr-FR' ? host.dataset.labelFailed : (error.message || host.dataset.labelFailed), true);
            }
        } finally {
            saving = false;
            if (!blockedByConflict && signature(latestScene) !== savedSignature) {
                saveTimer = setTimeout(save, 2000);
            }
        }
    };

    const changed = (elements, appState, files) => {
        if (readOnly || !acceptingChanges || blockedByConflict) return;
        latestScene = sceneForSave(elements, appState, files);
        if (signature(latestScene) === savedSignature) {
            displayStatus(host.dataset.labelSaved);
            return;
        }
        displayStatus(host.dataset.labelUnsaved);
        if (saveTimer) clearTimeout(saveTimer);
        saveTimer = setTimeout(save, 2000);
    };

    const start = async () => {
        try {
            const board = await request(host.dataset.boardUrl, 'GET');
            // PHP decodes an empty JSON object as an empty array when reading a
            // scene. Excalidraw expects its file map to be an object, even on
            // a fresh board, so normalize that edge at the editor boundary.
            const initialScene = {
                ...board.scene,
                files: Array.isArray(board.scene.files) ? {} : (board.scene.files || {}),
            };
            updateRevision(board.revision);
            latestScene = initialScene;
            savedSignature = signature(initialScene);
            displayStatus(readOnly ? host.dataset.labelReadonly : host.dataset.labelSaved);
            createRoot(host).render(React.createElement(Excalidraw, {
                initialData: initialScene,
                langCode: host.dataset.locale === 'fr-FR' ? 'fr-FR' : 'en',
                viewModeEnabled: readOnly,
                onChange: changed,
                UIOptions: {
                    canvasActions: {
                        loadScene: false,
                        saveToActiveFile: false,
                        export: false,
                        saveAsImage: true,
                    },
                },
            }));
            // The editor emits an initial onChange while mounting. It is not a
            // user edit and must not create a spurious revision.
            setTimeout(() => { acceptingChanges = true; }, 750);
        } catch (error) {
            displayStatus(host.dataset.locale === 'fr-FR' ? host.dataset.labelFailed : (error.message || host.dataset.labelFailed), true);
        }
    };

    saveButton?.addEventListener('click', save);
    restoreButton?.addEventListener('click', async () => {
        const source = Number(history.value);
        if (history.value === '' || !Number.isInteger(source)) return;
        if (!window.confirm(host.dataset.labelRestoreConfirm)) return;
        restoreButton.disabled = true;
        try {
            await request(`${host.dataset.revisionsUrl}/${source}/restore`, 'POST', {expectedRevision: currentRevision});
            window.location.reload();
        } catch (error) {
            displayStatus(error.status === 409 ? host.dataset.labelConflict :
                (host.dataset.locale === 'fr-FR' ? host.dataset.labelFailed : (error.message || host.dataset.labelFailed)), true);
            restoreButton.disabled = false;
        }
    });
    window.addEventListener('beforeunload', (event) => {
        if (!readOnly && !blockedByConflict && latestScene && signature(latestScene) !== savedSignature) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
    start();
}
