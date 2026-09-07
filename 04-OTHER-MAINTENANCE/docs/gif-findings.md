# Đối chiếu GIF Mimi

Nguồn hiện có hai GIF `lv_0_20260905143634.gif` và `lv_0_20260905144042.gif`; chưa có file tên literal `624.gif` hoặc `042.gif` trong thư mục nguồn.

GIF thứ nhất có kích thước 238×426, khung dọc với vùng đen trên/dưới và nền hồng nhạt; GIF thứ hai có kích thước 240×240, khung vuông với nền xám nhạt. Vì hai khung khác tỉ lệ và nền, nếu đặt trực tiếp vào cùng UI sẽ tạo cảm giác một ảnh nhỏ/một ảnh lớn và không đồng nhất nền/góc.

Trong `legacy.html`, Mimi hiện dùng các PNG `mimi_ready`, `mimi_thinking`, `mimi_help`, `mimi_success` qua `assetFiles`; `setMood()` đổi URL ảnh nhưng chưa có trạng thái GIF mặc định/trả lời theo yêu cầu, chưa kiểm soát tốc độ 0,75× của GIF. `chatgpt.js` gọi `askServer()` tại action `ai_chat` và cần chuyển Mimi sang trạng thái trả lời trước `await`, rồi trở về mặc định trong `finally`.
