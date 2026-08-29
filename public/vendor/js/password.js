document.addEventListener('DOMContentLoaded', function () {

    /*
    |--------------------------------------------------------------------------
    | Show / Hide Password
    |--------------------------------------------------------------------------
    */
    document.addEventListener('click', function (e) {

        const button = e.target.closest('.password-toggle');

        if (!button) return;

        const input = document.getElementById(
            button.dataset.target
        );

        const icon = button.querySelector('i');
        if (input.type === 'password') {

            input.type = 'text';

            icon.classList.remove('ti-eye');
            icon.classList.add('ti-eye-off');

        } else {

            input.type = 'password';

            icon.classList.remove('ti-eye-off');
            icon.classList.add('ti-eye');
        }
    });

    /*
    |--------------------------------------------------------------------------
    | Password Validation
    |--------------------------------------------------------------------------
    */
    document.querySelectorAll('.password-input')
        .forEach(function(input) {

        input.addEventListener('input', function() {

            const name = this.id;
            const value = this.value;

            updateRule(
                `${name}-length`,
                value.length >= 8
            );

            updateRule(
                `${name}-uppercase`,
                /[A-Z]/.test(value)
            );

            updateRule(
                `${name}-number`,
                /\d/.test(value)
            );

            updateRule(
                `${name}-special`,
                /[!@#$%^&*(),.?":{}|<>]/.test(value)
            );

        });

    });

    function updateRule(id, valid)
    {
        const element = document.getElementById(id);

        if (!element) return;

        const text = element.textContent
            .replace('✔','')
            .replace('✖','')
            .trim();

        if (valid) {

            element.classList.remove('text-danger');
            element.classList.add('text-success');

            element.innerHTML = '✔ ' + text;

        } else {

            element.classList.remove('text-success');
            element.classList.add('text-danger');

            element.innerHTML = '✖ ' + text;
        }
    }

});