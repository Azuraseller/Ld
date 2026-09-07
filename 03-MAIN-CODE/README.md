# 03 — Main Code

Đây là nhóm code chức năng chính của server.

- `../server/_core/index.ts`: entrypoint Express/Vite production.
- `../server/routers.ts`: tRPC router.
- `../server/legacyApi.ts`: API bridge cho các action legacy.
- `../server/phpLegacy.ts`: chạy PHP legacy và stream tiến độ.
- `../server/db.ts`: truy cập database.
- `../api3.php`: API PHP legacy.
- `../Dockerfile`: runtime Node.js/PHP production.

**Lưu ý:** các đường dẫn runtime được giữ nguyên để không ảnh hưởng API, PHP, auth, database hoặc build.
