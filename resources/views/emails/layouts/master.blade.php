<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>{{ $subject }}</title>
</head>

<body style="
background:#f4f7fb;
padding:10px;
font-family:Arial,sans-serif;
">

    <table width="100%">
        <tr>
            <td align="center">

                <table width="650"
                    style="
background:#ffffff;
border-radius:12px;
overflow:hidden;
box-shadow:0 2px 10px rgba(0,0,0,.08);
">

                    <tr>
                        <td style="
background:#1e40af;
padding:25px;
text-align:center;
">
                            <h1 style="
color:#ffffff;
margin:0;
">
                                🏥 HMS
                            </h1>
                        </td>
                    </tr>

                    <tr>
                        <td style="
padding:40px;
font-size:15px;
line-height:1.8;
color:#374151;
">

                            {!! $content !!}

                        </td>
                    </tr>

                    <tr>
                        <td
                            style="
background:#f9fafb;
padding:20px;
text-align:center;
font-size:12px;
color:#6b7280;
">
                            © {{ date('Y') }} HMS.
                            All rights reserved.
                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>

</body>

</html>
