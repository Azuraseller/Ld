# Đồng bộ source GitHub và cập nhật ứng dụng

Repository được theo dõi là `Azuraseller/ld-tool-web`, nhánh `main`. Backend dùng GitHub REST API để đọc commit mới nhất tại `GET /repos/Azuraseller/ld-tool-web/commits/main`; token chỉ nằm ở biến môi trường server `GITHUB_TOKEN`, không được gửi xuống trình duyệt.

Khi tài khoản `MimiVip01` đăng nhập, giao diện kiểm tra commit mới theo chu kỳ 5 phút. Nếu SHA remote thay đổi, backend gọi `github_sync`, pull fast-forward source, chạy build và tải lại giao diện khi build thành công. Lịch nền hiện tại vẫn kiểm tra mỗi 3600 giây để cập nhật deployment kể cả khi không có trình duyệt mở.

Ứng dụng được đóng gói theo PWA/WebAPK. Vì vậy APK mẫu không chứa toàn bộ source PHP/JS; nó mở URL web đã triển khai. Khi web được build lại, WebAPK nhận giao diện mới sau lần tải lại tiếp theo. Android không thể tự thay thế một APK native đã cài chỉ bằng GitHub API; muốn cập nhật native binary phải phát hành APK mới qua kênh phân phối và yêu cầu cài đặt/update riêng.

## Biến môi trường production

```text
GITHUB_REPO=Azuraseller/ld-tool-web
GITHUB_TOKEN=<fine-grained token chỉ cấp quyền Contents cần thiết cho repository này>
```

Không commit token vào GitHub, không đặt token trong `client/`, `public/`, `legacy.html` hoặc localStorage. Token từng bị gửi trong chat phải được revoke và tạo lại.
