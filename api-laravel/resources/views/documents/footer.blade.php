{{-- Port of Rails' app/views/templates/documents/footer.slim — the PDF page
     footer passed to Gotenberg as footer.html. --}}
<!doctype html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-weight: 400;
            font-display: swap;
            src: local("Inter-Regular");
        }

        body {
            box-sizing: border-box;
            margin: 0;
            padding: 0 calc(0.42in + 10.24px);
            color: #66758f;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
            font-family: Inter, sans-serif;
            font-size: 11.52px;
            line-height: 20.48px;
        }

        .footer {
            line-height: 1;
            color: #66758f;
            display: flex;
            justify-content: space-between;
            width: 100%;
            border-top: 1px solid #D9DEE7;
            padding: 15.36px 0;
        }
    </style>
</head>
<body>
<div class="footer">
    <span>{{ $number }}</span>
    <span>{!! __('document.page_numbering', ['current' => '<span class="pageNumber"></span>', 'total' => '<span class="totalPages"></span>']) !!}</span>
</div>
</body>
</html>
