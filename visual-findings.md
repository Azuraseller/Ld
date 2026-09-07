# Kiểm tra preview sau tích hợp Mimi GIF

Sau khi restart, preview tải được toàn bộ giao diện FunPass Auto ở desktop. Mimi hiển thị ở góc dưới phải với khung vuông, nền đồng nhất màu xám nhạt và không còn vùng đen thừa của GIF dọc. Các tab/khối chính vẫn giữ nguyên bố cục.

Đã gỡ thẻ `<script src="chatgpt.js?v=opt84">` vì legacy.html đã nhúng inline chatgpt đầy đủ; thẻ cũ khiến Vite fallback trả HTML và tạo lỗi `Unexpected token '<'`. Home.tsx đã có guard HMR để tránh chạy lại inline lexical bindings như `viewMode` khi preview hot-reload.

Action `ai_chat` đã được nối vào helper LLM server-side và smoke test trả JSON hợp lệ với `ok:true`, `answer`, `model`.
