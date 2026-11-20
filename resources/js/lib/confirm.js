// In-page confirmation dialog (replaces the browser's native confirm(),
// which automation and some in-app browsers silently dismiss as "Cancel").
// Usage:  if (await confirmDialog('Delete this?')) { ... }
// The <ConfirmHost /> mounted in AppLayout renders the dialog.
let listener = null;

export function confirmDialog(message) {
    return new Promise((resolve) => {
        if (listener) {
            listener({ message, resolve });
        } else {
            resolve(window.confirm(message));
        }
    });
}

export function setConfirmListener(fn) {
    listener = fn;
}
