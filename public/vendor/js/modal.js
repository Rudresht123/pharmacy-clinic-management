function loadModal(url, modalId) {

    const modalEl = document.getElementById(modalId);

    if (!modalEl) {
        console.error(`Modal '${modalId}' not found.`);
        return;
    }

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

    modal.show();
    $(modalEl).find('.modal-body').html(`
        <div class="d-flex flex-column align-items-center justify-content-center py-5">
            <div class="spinner-border text-primary mb-3"></div>
            <h6 class="mb-1">Please Wait...</h6>
            <small class="text-muted">Fetching data from server</small>
        </div>
    `);

    $(modalEl).find('.modal-body').load(url, function(response, status, xhr) {

        if (status === 'error') {

            let errorTitle = 'Something Went Wrong';
            let errorMessage = 'Unable to load the requested content.';

            if (xhr.status === 404) {
                errorTitle = 'Record Not Found';
                errorMessage = 'The requested record may have been deleted or does not exist.';
            }

            $(modalEl).find('.modal-body').html(`
                <div class="modal-error-state text-center py-5">
                    <div class="error-icon mb-3">
                        <i class="ti ti-alert-circle"></i>
                    </div>

                    <h5 class="fw-bold mb-2">${errorTitle}</h5>

                    <p class="text-muted mb-4">
                        ${errorMessage}
                    </p>
                </div>
            `);
        }
    });
}

$(document).on('click', '.editUrl', function () {

    let url = $(this).data('url');
    let modalId = $(this).data('modalid');

    loadModal(url, modalId);

});



// Delete Button Confirmation Section
$(document).on('click', '.deleteBtn', function () {

    let url = $(this).data('url');
    let title = $(this).data('title') ?? 'Record';

    Swal.fire({
        title: `Delete ${title}?`,
        html: `
            <div class="delete-modal-content">
                <div class="delete-icon-wrapper">
                    <i class="ti ti-trash"></i>
                </div>

                <p class="delete-description">
                    This action is permanent and cannot be undone.
                    Once deleted, the selected ${title.toLowerCase()} will be removed forever.
                </p>
            </div>
        `,
        showCancelButton: true,
        reverseButtons: true,
        focusCancel: true,
        confirmButtonText: `
            <i class="ti ti-trash me-1"></i>
            Delete
        `,
        cancelButtonText: `
            <i class="ti ti-x me-1"></i>
            Cancel
        `,
        customClass: {
            popup: 'custom-delete-popup',
            confirmButton: 'btn btn-danger delete-confirm-btn',
            cancelButton: 'btn btn-light delete-cancel-btn'
        },
        buttonsStyling: false
    }).then((result) => {

        if (!result.isConfirmed) return;

        $.ajax({
            url: url,
            type: 'DELETE',
            data: {
                _token: $('meta[name="csrf-token"]').attr('content')
            },

            beforeSend: function () {

            Swal.fire({
                title: `Delete ${title}?`,
                html: `
                    <div class="delete-modal-content">
                        <div class="delete-icon-wrapper">
                            <i class="ti ti-trash"></i>
                        </div>

                        <p class="delete-description">
                            This action cannot be undone.
                            The selected ${title.toLowerCase()} will be permanently removed.
                        </p>
                    </div>
                `,
                showCancelButton: true,
                reverseButtons: true,
                focusCancel: true,

                confirmButtonText: `
                    <i class="ti ti-trash me-1"></i>
                    Yes, Delete
                `,

                cancelButtonText: `
                    <i class="ti ti-x me-1"></i>
                    Cancel
                `,
                    customClass: {
                        popup: 'custom-delete-popup',
                        actions: 'swal-action-buttons',
                        confirmButton: 'delete-confirm-btn',
                        cancelButton: 'delete-cancel-btn'
                    },

                buttonsStyling: false
            });
            },

            success: function (response) {

                Swal.fire({
                    icon: 'success',
                    title: 'Deleted Successfully',
                    text: response.message,
                    confirmButtonText: 'Continue',
                    customClass: {
                        confirmButton: 'btn btn-success'
                    },
                    buttonsStyling: false
                }).then(() => {

                    if ($.fn.DataTable.isDataTable('#datatable')) {

                        $('#datatable')
                            .DataTable()
                            .ajax
                            .reload(null, false);

                    } else {

                        location.reload();

                    }

                });

            },

            error: function (xhr) {

                Swal.fire({
                    icon: 'error',
                    title: 'Deletion Failed',
                    text: xhr.responseJSON?.message || 'Something went wrong.',
                    confirmButtonText: 'Close',
                    customClass: {
                        confirmButton: 'btn btn-danger'
                    },
                    buttonsStyling: false
                });

            }
        });

    });

});