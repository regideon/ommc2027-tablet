# Photo Integration Playbook

## NativePHP Mobile 3.3.6 asynchronous operation results

`PendingPhotoCapture::start()` and `PendingMediaPicker::start()` report success only when the return from `nativephp_call()` decodes to a JSON object containing `status: success`. On iOS, `BridgeResponse.success(data:)` returns its `data` dictionary directly. The app-owned asynchronous `Camera.GetPhoto` and `Camera.PickMedia` functions return an empty dictionary because their results arrive later as events. The bridge response is therefore `{}`, and both PHP wrappers can return `false` even though the native operation was invoked.

Treat these `start()` booleans as the wrapper's parsed response shape, not proof that camera/gallery invocation failed. Do not clear the pending capture ID or discard its context because the boolean is false. Resolve the operation through the correlated `PhotoTaken`, `PhotoCancelled`, `PermissionDenied`, or `MediaSelected` event. Keep diagnostics named to reflect the returned value (for example, `start_reported_success`), not native acceptance.

### Callback payload contract

- `PhotoTaken`: `path`, `mimeType`, optional `id`.
- `PhotoCancelled`: `cancelled`, optional `id`. Use `cancelled=true` for user cancellation and `cancelled=false` for operational failure; do not send extra named parameters.
- `PermissionDenied`: `action`, optional `id`.
- `MediaSelected`: `success`, `files`, `count`, optional `error`, `cancelled`, optional `id`.

The event payload must match the corresponding NativePHP PHP event constructor. A constructor mismatch can prevent callback dispatch before Livewire photo persistence runs.
