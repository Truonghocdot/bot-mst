# Ghi chú Thử thách CapSolver Cloudflare

Nguồn:
- https://docs.capsolver.com/en/guide/captcha/cloudflare_challenge/

## Những Gì Trang Này Đề Cập

Trang CapSolver này nói về `Cloudflare Challenge`, không phải `Cloudflare Turnstile`.

Dấu hiệu phù hợp với thử thách này:
- Trang trả về `Just a moment...`
- HTML có `challenge-platform` hoặc `_cf_chl_opt`
- Thường là phản hồi (response) `403` và cần vượt qua thử thách để lấy `cf_clearance`

Loại tác vụ (Task type) theo tài liệu (docs):
- `AntiCloudflareTask`

## Đầu Vào (Inputs) CapSolver Yêu Cầu

Theo tài liệu, tác vụ cần các trường sau:
- `type`: `AntiCloudflareTask`
- `websiteURL`: URL trang đích
- `proxy`: proxy bắt buộc; tài liệu khuyên dùng `static proxy` (proxy tĩnh) hoặc `sticky proxy` (proxy dính)

Các trường tùy chọn nhưng quan trọng:
- `userAgent`: nên giữ giống user-agent đang dùng khi request trang đích
- `html`: HTML thử thách trang trả về, thường có `Just a moment...`; tài liệu nói một số trang cần gửi kèm HTML này

Ghi chú quan trọng từ tài liệu:
- Nên dùng `static proxy` hoặc `sticky proxy`, không dùng proxy xoay vòng (rotating proxy)
- Nếu thất bại liên tục, IP/proxy có thể đã bị chặn (block)
- Nên giữ `userAgent` đồng nhất
- Phía request tới trang web đích nên dùng thư viện TLS request (TLS request library)

## Luồng API (Flow API) Theo Tài Liệu

1. Gọi `createTask`
2. Truy vấn liên tục (Poll) `getTaskResult`
3. Nếu `status=ready` thì lấy `solution` (giải pháp)

Thời gian tài liệu mô tả:
- Thường trong khoảng từ `2s` đến `20s`, tùy thuộc vào trang web và proxy

## Đầu Ra Cần Dùng

Khi tác vụ thành công, tài liệu cho thấy `solution` có thể trả về:
- `cookies.cf_clearance`
- `token`
- `userAgent`

Trong thực tế, thứ thường cần nhất cho worker là:
- cookie `cf_clearance`
- `userAgent` khớp với phiên giải thử thách

## Ví Dụ Cấu Trúc Payload

Ví dụ cấu trúc request theo tài liệu:

```json
{
  "clientKey": "YOUR_API_KEY",
  "task": {
    "type": "AntiCloudflareTask",
    "websiteURL": "https://example.com",
    "proxy": "ip:port:user:pass",
    "userAgent": "Mozilla/5.0 ... Chrome/141.0.0.0 ...",
    "html": "<!DOCTYPE html><html><head><title>Just a moment...</title>..."
  }
}
```

Ví dụ kết quả cần quan tâm:

```json
{
  "status": "ready",
  "solution": {
    "cookies": {
      "cf_clearance": "..."
    },
    "token": "...",
    "userAgent": "Mozilla/5.0 ..."
  }
}
```

## Cách Áp Dụng Cho Kho Lưu Trữ (Repo) Này

Cho `masothue.com`, worker đã từng gặp thử thách HTML kiểu:
- `Just a moment...`
- `/cdn-cgi/challenge-platform/...`

Nên nếu cần thêm CapSolver vào worker hiện tại, luồng hợp lý là:

1. Worker request trang đích bằng proxy sticky
2. Nếu phát hiện HTML `Just a moment...`, lưu lại HTML đó
3. Gửi `websiteURL + proxy + userAgent + html` lên CapSolver với `AntiCloudflareTask`
4. Truy vấn liên tục (Poll) cho đến khi nhận được `cf_clearance`
5. Nạp cookie `cf_clearance` vào ngữ cảnh trình duyệt (browser context) hoặc session request
6. Tiếp tục thu thập dữ liệu (crawl) bằng cùng proxy và user-agent đã dùng lúc giải (solve)

## Ghi Chú Thực Tế Cho Chúng Ta

- Redis hiện tại của repo chỉ nên dùng cho hàng đợi/tác vụ (queue/job), không phải nơi giải thử thách
- Trạng thái (State) để so sánh dữ liệu doanh nghiệp vẫn nên lưu ở SQLite/PostgreSQL
- Nếu thêm CapSolver, cần thêm biến môi trường (env) riêng cho:
  - `CAPSOLVER_API_KEY`
  - `CAPSOLVER_PROXY`
  - `CAPSOLVER_ENABLED`
- Nếu dùng phiên trình duyệt (browser session), nên lưu lại trạng thái cookie/session sau khi qua thử thách

## Kết Luận Ngắn Gọn

Tài liệu CapSolver này dùng cho `Cloudflare managed challenge` (thử thách do Cloudflare quản lý) và phù hợp với dấu hiệu mà `masothue.com` đã trả về trước đó. Nếu trang lại bật thử thách, hướng tích hợp đúng là giải `AntiCloudflareTask` để lấy `cf_clearance`, rồi tiếp tục crawl bằng cùng proxy + user-agent.
