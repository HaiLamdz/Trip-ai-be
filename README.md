# TripAI Backend

Backend Laravel cho ứng dụng lập kế hoạch du lịch TripAI.

## Giới thiệu

Dự án là backend xây dựng bằng Laravel 12 và PHP 8.2, cung cấp API cho:
- Đăng ký / đăng nhập với JWT
- Quản lý người dùng và hồ sơ
- Tạo và quản lý chuyến đi
- Lưu địa điểm yêu thích và địa điểm đã lưu
- Thông báo và nhật ký hoạt động
- Chia sẻ chuyến đi công khai
- Sinh chuyến đi và trò chuyện AI

## Công nghệ chính

- PHP 8.2
- Laravel 12
- MySQL
- Redis / Predis
- JWT Auth (tymon/jwt-auth)
- Spatie Query Builder
- Vite + Tailwind CSS
- Leaflet

## Tính năng chính

- Xác thực JWT: `register`, `login`, `logout`, `refresh`, `me`
- API quản lý chuyến đi: tạo, xem, cập nhật, xóa, nhân bản, gợi ý, chi phí, ghi chú, packing list
- Public share: chia sẻ chuyến đi qua token công khai
- API lưu địa điểm và yêu thích
- Thông báo và đánh dấu đã đọc
- Nhật ký hoạt động
- AI endpoint: tạo chuyến đi mới và chat trong chuyến đi
- Cron trigger: `/api/cron/run-schedule`
- Health check: `/api/health`

## Yêu cầu hệ thống

- PHP 8.2
- Composer
- MySQL
- Redis
- Node.js / npm

## Cài đặt nhanh

1. Clone repository

```bash
composer install
cp .env.example .env
php artisan key:generate
```

2. Cấu hình file `.env`

- `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- `JWT_SECRET` (tạo bằng `php artisan jwt:secret` nếu cần)
- `OPENWEATHER_API_KEY` nếu cần dữ liệu thời tiết
- `AI_PROVIDER`, `GEMINI_API_KEY`, `OPENAI_API_KEY`

3. Migrate database

```bash
php artisan migrate
```

4. Cài đặt frontend assets (nếu sử dụng giao diện hoặc dev server)

```bash
npm install
npm run build
```

## Chạy ứng dụng

```bash
php artisan serve
```

Hoặc sử dụng script `setup` đã định nghĩa:

```bash
composer setup
```

## Phát triển

```bash
npm run dev
```

## Kiểm tra

```bash
composer test
```

## Endpoints API chính

- `GET /api/health`
- `POST /api/auth/register`
- `POST /api/auth/login`
- `POST /api/auth/logout`
- `GET /api/auth/me`
- `POST /api/auth/refresh`
- `GET /api/trips/share/{token}`
- `GET /api/trips`
- `GET /api/trips/{id}`
- `DELETE /api/trips/{id}`
- `POST /api/trips/{id}/duplicate`
- `GET /api/trips/{id}/status`
- `PUT /api/trips/{id}/budget/actual`
- `POST /api/trips/{id}/favorites`
- `POST /api/trips/{id}/share`
- `PUT /api/trips/{id}/notes`
- `GET /api/trips/{id}/packing-list`
- `GET /api/trips/{id}/nearby`
- `GET /api/trips/{id}/cost-split`
- `POST /api/trips/{tripId}/days/{dayId}/places`
- `PUT /api/trips/{tripId}/days/{dayId}/places/{placeId}`
- `DELETE /api/trips/{tripId}/days/{dayId}/places/{placeId}`
- `GET /api/favorites`
- `POST /api/places/save`
- `GET /api/places/saved`
- `DELETE /api/places/saved/{id}`
- `GET /api/notifications`
- `GET /api/notifications/unread-count`
- `PUT /api/notifications/read-all`
- `PUT /api/notifications/{id}/read`
- `GET /api/activity-logs`
- `POST /api/cron/run-schedule`
- `POST /api/trips`
- `POST /api/trips/{id}/chat`

> Lưu ý: các endpoint bảo mật yêu cầu header `Authorization: Bearer {token}`.

## Licence

Mã nguồn sử dụng giấy phép MIT.
