/* =====================================
   Dynamic Alert Function
===================================== */

function showAlert(type = 'success', message = '') {

    let title = type === 'success'
        ? 'Success'
        : 'Error';

    let icon = type === 'success'

        ? `<svg xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke-width="2.5"
                stroke="currentColor">

                <path stroke-linecap="round"
                      stroke-linejoin="round"
                      d="M5 13l4 4L19 7"/>

           </svg>`

        : `<svg xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke-width="2.5"
                stroke="currentColor">

                <path stroke-linecap="round"
                      stroke-linejoin="round"
                      d="M6 18L18 6M6 6l12 12"/>

           </svg>`;

    let alert = document.createElement('div');

    alert.className = `custom-alert ${type}-alert`;

    alert.innerHTML = `

        <div class="alert-icon">
            ${icon}
        </div>

        <div class="alert-content">
            <h5>${title}</h5>
            <p>${message}</p>
        </div>

        <button class="alert-close">
            ×
        </button>

    `;

    document
        .getElementById('alert-container')
        .appendChild(alert);

    /* Show Animation */

    setTimeout(() => {

        alert.classList.add('show-alert');

    }, 100);

    /* Auto Remove */

    setTimeout(() => {

        removeAlert(alert);

    }, 4000);

    /* Close */

    alert.querySelector('.alert-close')
        .addEventListener('click', () => {

            removeAlert(alert);

        });

}

/* Remove */

function removeAlert(alert){

    alert.classList.remove('show-alert');

    setTimeout(() => {

        alert.remove();

    }, 300);

}



