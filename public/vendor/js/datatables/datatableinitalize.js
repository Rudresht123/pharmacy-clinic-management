$(document).ready(function () {

    $('.datatable').DataTable({

        /* =================================
           Layout
        ================================= */

        dom:
            "<'row align-items-center mb-3'<'col-md-6'l><'col-md-6 text-md-end text-start mt-2 mt-md-0'f>>" +

            "<'row'<'col-12'tr>>" +

            "<'row align-items-center mt-3'<'col-md-5 text-md-start text-center'i><'col-md-7 text-md-end text-center mt-2 mt-md-0'p>>",

        /* =================================
           Page Length
        ================================= */

        pageLength: 10,

        lengthMenu: [
            [10, 25, 50, 100],
            [10, 25, 50, 100]
        ],

        /* =================================
           Language
        ================================= */

        language: {

            lengthMenu:
                "Show _MENU_ Entries",

            search:
                "",

            searchPlaceholder:
                "Search here...",

            info:
                "Showing _START_ to _END_ of _TOTAL_ entries",

            infoEmpty:
                "No entries available",

            zeroRecords:
                "No matching records found",

            paginate: {
                previous:
                    '<i class="ti ti-chevron-left"></i>',

                next:
                    '<i class="ti ti-chevron-right"></i>'
            }

        },

        /* =================================
           Features
        ================================= */

        paging: true,
        searching: true,
        ordering: true,
        info: true,
        responsive: true,
        autoWidth: false,

        /* =================================
           Column Settings
        ================================= */

        columnDefs: [
            {
                targets: -1,
                orderable: false,
                searchable: false
            }
        ]

    });

});