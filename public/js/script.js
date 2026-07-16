htmx.on('htmx:responseError', function (event) {
    const status = event.detail.xhr.status;
    if (status === 401) {
        window.location.href = '/sign-in';
        return;
    }
    if (status === 500) {
        // Show the error page in the main view. Replacing the whole <html>
        // would kill the audio element and every loaded script.
        const view = document.querySelector('#view');
        if (view) {
            view.innerHTML = event.detail.xhr.response;
            return;
        }
    }
    console.error('htmx response error', status);
});
