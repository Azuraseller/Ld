# Ground-truth triển khai

Đây là yêu cầu dựng lại nguyên trạng từ hai tệp nguồn do người dùng cung cấp: `api3.php` và `chatgpt.js`. Không chọn hướng thiết kế thay thế, không tự ý làm mới giao diện, không đổi văn bản, màu sắc, bố cục, tên nút, hành vi hoặc cấu trúc chức năng.

## Phạm vi trung thành

`api3.php` là nguồn sự thật cho màn hình FunPass API Control Panel, các tab, biểu mẫu, bảng điều khiển, console, trạng thái, CSS nội tuyến và các lời gọi API. `chatgpt.js` là nguồn sự thật cho Mimi AI Buddy, CSS nội tuyến, âm thanh, cửa sổ hội thoại, lịch sử, upload và các hành vi tương tác.

## Cách triển khai

Trang web sẽ giữ lại nguyên văn phần giao diện HTML/CSS/JavaScript của `api3.php` và nạp `chatgpt.js` ở lớp trang. Các tài nguyên `assets/mimi_ready.png` và trạng thái liên quan được ánh xạ vào tài sản web tương ứng để tránh lỗi hiển thị. Không thực hiện tái thiết kế bằng React component hay Tailwind nếu việc đó làm thay đổi source.

## Giới hạn runtime cần ghi nhận

`api3.php` ban đầu chạy trên PHP >= 8.0 và dùng các action phía máy chủ (`?action=...`) cùng cURL/OpenSSL. Bản preview web tĩnh có thể hiển thị nguyên trạng giao diện và chuyển tab, nhưng các action cần PHP backend sẽ phụ thuộc vào runtime tương thích. Không giả lập kết quả API, không tạo dữ liệu giả và không đổi mã nguồn nghiệp vụ để che giấu giới hạn này.
