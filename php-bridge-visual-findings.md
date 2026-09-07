# Kiểm tra preview sau PHP bridge

Preview sau khi thêm Dockerfile và PHP bridge vẫn tải bình thường ở desktop. Các tab Funpass Auto, Khoga Flow, Mua Thẻ, Proxy và Tài Khoản vẫn hiển thị; khối console và Mimi không bị thay đổi bố cục. Smoke test local xác nhận `modules_list` trả JSON từ api3.php và `fp_create_funpass` đi qua PHP bridge, trả lỗi validation OTP dạng JSON nghiệp vụ thay vì lỗi 501.

Bản production cần được publish với Dockerfile để image có PHP CLI; sandbox local đã kiểm tra cú pháp api3.php và cài đủ extension cURL, mbstring, XML.
