# LD Tool Web — Organized Source

Repository này được sắp xếp thành bốn mục logic:

1. `01-DATABASE/` — hướng dẫn database; source thực tế nằm tại `drizzle/` để giữ compatibility.
2. `02-INTERFACE/` — hướng dẫn giao diện; source thực tế nằm tại `client/` để Vite build đúng.
3. `03-MAIN-CODE/` — hướng dẫn code chức năng chính; source thực tế nằm tại `server/` và `api3.php`.
4. `04-OTHER-MAINTENANCE/` — tài liệu và script ít sử dụng.

Các file và thư mục được runtime tham chiếu trực tiếp không bị di chuyển hoặc đổi tên. Vì vậy các lệnh hiện tại vẫn giữ nguyên:

```bash
pnpm install
pnpm run check
pnpm run build
pnpm run start
```

Mục tiêu của cấu trúc này là dễ tìm file mà không làm thay đổi hành vi ứng dụng.
