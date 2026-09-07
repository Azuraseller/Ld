# Kiểm tra lỗi bố cục và API

Sau khi nâng cấp full-stack, dependency bị thiếu khiến dev server ban đầu dừng ở `ERR_MODULE_NOT_FOUND: dotenv`; đã cài dependency và khởi động lại thành công.

Nguyên nhân cảnh báo đỏ là request `GET /?action=proxy_get` bị Vite fallback trả HTML HTTP 200 thay vì JSON. Đã đăng ký `registerLegacyApi` trước Vite fallback. Smoke test xác nhận `proxy_get`, `ping`, `auth_status` trả JSON; smoke test CRUD xác nhận `proxy_save -> proxy_get`, `acc_save -> acc_list -> acc_delete`, và `mimi_memory_save -> mimi_memory_get` đọc/ghi được qua bảng `legacy_records`.

Nguyên nhân bố cục desktop bị co là vỏ React dùng thẻ `main`, bị CSS `main{...max-width:1100px}` của nguồn api3.php áp nhầm. Đã đổi vỏ thành `div` và gắn trực tiếp DOM/style/script nguồn, bỏ iframe. Responsive mobile tự chuyển sang chế độ `mob` khi chưa có lựa chọn của người dùng; lựa chọn đã lưu vẫn được tôn trọng.

Vitest và TypeScript đều đạt: 3 tests passed, `tsc --noEmit` không lỗi. Production build đạt.
