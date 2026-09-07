# 01 — Database

Nhóm này quản lý dữ liệu database của ứng dụng.

- `../drizzle/schema.ts`: schema Drizzle đang được runtime sử dụng.
- `../drizzle/relations.ts`: quan hệ bảng.
- `../drizzle/*.sql`: migration.
- `../drizzle.config.ts`: cấu hình Drizzle.

**Lưu ý:** thư mục `drizzle/` vẫn giữ nguyên vị trí vì `package.json`, `drizzle.config.ts` và backend đang tham chiếu trực tiếp đường dẫn này. Không đổi tên hoặc di chuyển thư mục nếu chưa cập nhật cấu hình và migration.
