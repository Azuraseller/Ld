# Báo cáo đối chiếu api3.php

## Kết luận nguồn

`/home/ubuntu/upload/api3.php` và `/home/ubuntu/projects/ld-tool-770cf429/api3.php` giống hệt nhau theo SHA-256 `b3a50b6118c2f49a94ee76b14271865549b10de0fcb01f7e7f786a60013fb895`. Không phát hiện khác biệt giữa file đính kèm và bản gốc.

## Chức năng đã kiểm tra và sửa

| Hạng mục | Kết quả |
|---|---|
| `proxy_get`, `proxy_save`, `proxy_add`, `proxy_reset_state` | Đã có bridge database; thêm `proxy_add` và `proxy_reset_state` còn thiếu |
| `acc_list`, `acc_save`, `acc_delete` | Đã có bridge database |
| `runlog_list`, `runlog_clear` | Đã có bridge database |
| `mimi_memory_*`, `mimi_chats_*` | Đã có bridge database |
| `ai_chat` | Đã nối helper LLM server-side; smoke test trả JSON `ok:true` |
| Nạp Mimi | Đã bỏ thẻ `chatgpt.js` ngoài bị fallback HTML; giữ inline chatgpt để tránh `Unexpected token '<'` |
| HMR preview | Đã thêm guard tránh chạy lại inline lexical bindings gây trùng `viewMode` |
| Mimi GIF | Hai GIF được chuẩn hóa thành 240×240, nền #eeeeee, góc bo đồng nhất, playback 0,75×; 624 là mặc định, 042 là thinking |

## Chức năng còn cần runtime PHP/upstream

Các action cần logic upstream của api3.php và credential/API bên ngoài hiện chưa được port đầy đủ vào Node bridge: `fp_create_funpass`, `fp_create_ldplayer`, `fp_login_funpass`, `fp_login_ldplayer`, `fp_wallet`, `fp_cpi_ads`, `fp_cpi_run`, `fp_transfer_detail`, `fp_transfer_execute`, `flow_khoga_full`, `flow_funpass_once`, các action mua thẻ `mt_login`, `mt_sync`, `mt_balance`, `mt_skus`, `mt_available_skus`, `mt_create_order`, `mt_buy_auto`, `mt_snipe`, `mt_gift_list`, `mt_gift_detail`, cùng nhóm module PHP `create_empty_file`, `module_upload`, `module_delete`, `modules_list`, `modules_remove`. Website hiện trả lỗi 501 minh bạch cho nhóm này thay vì giả báo thành công.

## Kiểm thử

Vitest: 5 tests passed. TypeScript check: passed. Production build: passed. `ai_chat` smoke test: trả `{"ok":true,"answer":"Mimi đang hoạt động.","model":"gemini-3.5-flash-lite","logs":[]}`. Hai URL GIF qua storage proxy trả HTTP 200 với `image/gif`.
