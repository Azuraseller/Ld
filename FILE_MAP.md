# Bản đồ file source

## Sửa giao diện và luồng legacy

| File | Vai trò | Khi nào sửa |
|---|---|---|
| `client/src/legacy.html` | HTML, CSS và JavaScript inline của giao diện PHP legacy | Đổi bố cục, trường hiển thị, nút, OTP/captcha UI và console |
| `client/src/legacy-source.js` | Widget Mimi AI và logic hỗ trợ | Đổi widget Mimi, chat, hướng dẫn hoặc upload module |
| `api3.php` | Chức năng PHP legacy, gọi upstream, OTP, captcha, proxy, tài khoản | Đổi hành vi API hoặc quy trình tạo tài khoản |
| `server/legacyApi.ts` | Route Node, whitelist action, stream NDJSON và lưu database | Thêm action, đổi route hoặc cách lưu response |
| `server/phpLegacy.ts` | Node chạy PHP CLI, timeout và chuyển log | Đổi runtime PHP, timeout hoặc environment bridge |

## Database

| File | Vai trò |
|---|---|
| `drizzle/schema.ts` | Khai báo bảng |
| `server/db.ts` | Query và upsert database |
| `drizzle/` | Migration SQL và metadata |

## Không nên sửa khi chỉ chỉnh legacy

`client/src/components/`, `server/_core/`, `shared/` và các file cấu hình build không cần thay đổi khi chỉ sửa `legacy.html` hoặc `api3.php`. Chỉ chạm vào chúng khi yêu cầu thực sự liên quan đến component, auth, runtime hoặc build.

## OTP và tốc độ

PHP bridge đặt `MIMI_WEB_BRIDGE=1`. Vì vậy khi chạy qua website, PHP trả trạng thái `otp_required` sau khoảng 10 giây để người dùng nhập OTP; chạy CLI thật vẫn giữ thời gian chờ tự động đầy đủ.
