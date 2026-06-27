# TripAI — API Documentation

> **Base URL:** `http://localhost:8000/api`  
> **Content-Type:** `application/json`  
> **Authentication:** JWT Bearer Token — thêm header `Authorization: Bearer <token>` cho mọi route được bảo vệ.

---

## Mục lục

- [Authentication](#authentication)
- [Profile](#profile)
- [Trips](#trips)
- [Trip Activities (Places)](#trip-activities-places)
- [Budget](#budget)
- [AI Chat](#ai-chat)
- [Saved Places](#saved-places)
- [Favorites](#favorites)
- [Notifications](#notifications)
- [Activity Logs](#activity-logs)
- [Utility](#utility)
- [Rate Limiting](#rate-limiting)
- [Error Responses](#error-responses)
- [Data Models](#data-models)

---

## Authentication

### POST `/auth/register`

Đăng ký tài khoản mới.

**Auth required:** Không

**Request Body**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `name` | string | ✅ | max:255 |
| `email` | string | ✅ | email, unique |
| `password` | string | ✅ | min:8 |
| `password_confirmation` | string | ✅ | phải khớp password |

```json
{
  "name": "Nguyễn Văn A",
  "email": "user@example.com",
  "password": "secret123",
  "password_confirmation": "secret123"
}
```

**Response `201`**

```json
{
  "message": "Đăng ký thành công",
  "user": {
    "id": 1,
    "name": "Nguyễn Văn A",
    "email": "user@example.com",
    "avatar": null,
    "phone": null,
    "bio": null,
    "created_at": "2024-01-01T00:00:00.000000Z"
  },
  "token": "eyJ0eXAiOiJKV1QiLCJhbGci...",
  "token_type": "bearer",
  "expires_in": 604800
}
```

---

### POST `/auth/login`

Đăng nhập, nhận JWT token.

**Auth required:** Không

**Request Body**

| Field | Type | Required |
|-------|------|----------|
| `email` | string | ✅ |
| `password` | string | ✅ |

```json
{
  "email": "user@example.com",
  "password": "secret123"
}
```

**Response `200`**

```json
{
  "message": "Đăng nhập thành công",
  "user": { "id": 1, "name": "Nguyễn Văn A", "email": "user@example.com" },
  "token": "eyJ0eXAiOiJKV1QiLCJhbGci...",
  "token_type": "bearer",
  "expires_in": 604800
}
```

**Response `401`** — Sai email/password

```json
{ "message": "Email hoặc mật khẩu không đúng" }
```

---

### POST `/auth/logout`

**Auth required:** ✅

**Response `200`**

```json
{ "message": "Đăng xuất thành công" }
```

---

### GET `/auth/me`

Lấy thông tin user hiện tại.

**Auth required:** ✅

**Response `200`**

```json
{
  "user": {
    "id": 1,
    "name": "Nguyễn Văn A",
    "email": "user@example.com",
    "avatar": "https://res.cloudinary.com/...",
    "phone": "0901234567",
    "bio": "Đam mê du lịch",
    "created_at": "2024-01-01T00:00:00.000000Z"
  }
}
```

---

### POST `/auth/refresh`

Làm mới JWT token.

**Auth required:** ✅ (token cũ còn trong refresh window)

**Response `200`**

```json
{
  "token": "eyJ0eXAiOiJKV1QiLCJhbGci...",
  "token_type": "bearer",
  "expires_in": 604800
}
```

**Response `401`** — Token hết hạn hoặc không hợp lệ

```json
{ "message": "Token không hợp lệ hoặc đã hết hạn" }
```

---

## Profile

### GET `/profile`

Lấy hồ sơ user kèm sở thích.

**Auth required:** ✅

**Response `200`**

```json
{
  "user": {
    "id": 1,
    "name": "Nguyễn Văn A",
    "email": "user@example.com",
    "avatar": null,
    "phone": null,
    "bio": null,
    "preferences": {
      "id": 1,
      "user_id": 1,
      "preferences": ["food", "nature", "culture"]
    }
  }
}
```

---

### PUT `/profile`

Cập nhật thông tin cá nhân. Hỗ trợ upload avatar (multipart/form-data).

**Auth required:** ✅

**Request Body** (`multipart/form-data` hoặc `application/json`)

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `name` | string | | max:255 |
| `phone` | string\|null | | max:20 |
| `bio` | string\|null | | max:1000 |
| `avatar` | file | | jpeg/png/webp, max 5MB |

**Response `200`**

```json
{
  "message": "Cập nhật hồ sơ thành công.",
  "user": { "id": 1, "name": "Tên mới", "avatar": "https://...", "phone": "09..." }
}
```

---

### PUT `/profile/preferences`

Cập nhật sở thích du lịch.

**Auth required:** ✅

**Request Body**

| Field | Type | Required | Giá trị hợp lệ |
|-------|------|----------|----------------|
| `preferences` | string[] | ✅ | `food` `cafe` `nature` `culture` `adventure` `shopping` `nightlife` `budget` `luxury` |

```json
{
  "preferences": ["food", "nature", "adventure"]
}
```

**Response `200`**

```json
{ "message": "Cập nhật sở thích thành công." }
```

---

## Trips

### POST `/trips`

Tạo lịch trình mới — AI sẽ tự động generate async.

**Auth required:** ✅  
**Rate limit:** 10 requests/phút (AI tier)

**Request Body**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `destination` | string | ✅ | max:255 |
| `origin` | string | | max:255 |
| `start_date` | string | ✅ | format `YYYY-MM-DD`, >= today |
| `duration_days` | integer | ✅ | 1–30 |
| `budget` | number | ✅ | > 0 |
| `num_people` | integer | ✅ | 1–20 |
| `travel_type` | string | | `solo` `couple` `family` `group` |
| `transport_mode` | string | | max:100 |
| `accommodation_type` | string | | `hotel` `homestay` `hostel` `resort` `airbnb` `villa` `other` |
| `accommodation_area` | string | | max:255 |
| `arrival_time` | string | | format `HH:MM` |
| `preferences` | string[] | | `food` `cafe` `nature` `culture` `adventure` `shopping` `nightlife` `budget` `luxury` |
| `notes` | string | | max:1000 |

```json
{
  "destination": "Đà Nẵng",
  "origin": "Hà Nội",
  "start_date": "2024-06-15",
  "duration_days": 4,
  "budget": 8000000,
  "num_people": 2,
  "travel_type": "couple",
  "transport_mode": "plane",
  "accommodation_type": "hotel",
  "accommodation_area": "Gần biển Mỹ Khê",
  "arrival_time": "14:00",
  "preferences": ["food", "nature"],
  "notes": "Thích ăn hải sản, không muốn đi quá nhiều"
}
```

**Response `202`** — Đã nhận, đang xử lý

```json
{
  "message": "Đang tạo lịch trình, vui lòng chờ.",
  "trip_id": 42,
  "status": "processing"
}
```

> **Lưu ý:** Sau khi nhận `202`, FE nên poll `GET /trips/{id}/status` mỗi 3 giây để theo dõi tiến độ.

---

### GET `/trips`

Lấy danh sách lịch trình của user (phân trang).

**Auth required:** ✅

**Query Params**

| Param | Type | Default |
|-------|------|---------|
| `page` | integer | 1 |

**Response `200`** — Laravel pagination

```json
{
  "current_page": 1,
  "data": [
    {
      "id": 42,
      "destination": "Đà Nẵng",
      "start_date": "2024-06-15",
      "duration_days": 4,
      "budget": "8000000.00",
      "num_people": 2,
      "status": "completed",
      "created_at": "2024-05-01T10:00:00.000000Z"
    }
  ],
  "per_page": 10,
  "total": 25,
  "last_page": 3
}
```

---

### GET `/trips/{id}`

Lấy chi tiết lịch trình, bao gồm đầy đủ các ngày, địa điểm, thời tiết và ngân sách.

**Auth required:** ✅

**Response `200`**

```json
{
  "trip": {
    "id": 42,
    "destination": "Đà Nẵng",
    "origin": "Hà Nội",
    "start_date": "2024-06-15",
    "duration_days": 4,
    "budget": 8000000,
    "num_people": 2,
    "travel_type": "couple",
    "transport_mode": "plane",
    "accommodation_type": "hotel",
    "status": "completed",
    "is_public": false,
    "share_token": null,
    "user_notes": null,
    "preferences": ["food", "nature"],
    "budget_data": {
      "food": "1200000.00",
      "transport": "2000000.00",
      "attraction": "800000.00",
      "accommodation": "3000000.00",
      "other": "400000.00",
      "total_estimated": "7400000.00",
      "total_actual": "0.00"
    },
    "days": [
      {
        "id": 101,
        "day_number": 1,
        "date": "2024-06-15",
        "weather": {
          "summary": "Nắng đẹp",
          "icon": "01d",
          "temperature_high": 34,
          "temperature_low": 26,
          "rain_probability": 0.1
        },
        "places": [
          {
            "id": 1001,
            "time": "14:00",
            "title": "Check-in khách sạn",
            "place_name": "Premier Village Danang Resort",
            "place_type": "hotel",
            "description": "Nhận phòng và nghỉ ngơi",
            "estimated_cost": "1500000.00",
            "duration_minutes": 30,
            "transport_to_next": "Đi bộ",
            "distance_to_next_km": "0.50",
            "latitude": "16.0544069",
            "longitude": "108.2021669",
            "sort_order": 0
          }
        ]
      }
    ]
  }
}
```

---

### GET `/trips/{id}/status`

Poll trạng thái xử lý lịch trình.

**Auth required:** ✅

**Response `200`**

```json
{
  "status": "processing",
  "progress_message": "AI đang tạo lịch trình cho bạn..."
}
```

**Giá trị `status`**

| Value | Ý nghĩa |
|-------|---------|
| `processing` | Đang được AI xử lý |
| `completed` | Xong, có thể xem chi tiết |
| `failed` | Thất bại, cần tạo lại |
| `draft` | Nháp chưa hoàn chỉnh |

---

### DELETE `/trips/{id}`

Xóa lịch trình.

**Auth required:** ✅

**Response `204`** — No Content

---

### POST `/trips/{id}/duplicate`

Tạo bản sao lịch trình với status `draft`.

**Auth required:** ✅

**Response `201`**

```json
{
  "message": "Đã tạo bản sao lịch trình.",
  "trip": { "id": 43, "destination": "Đà Nẵng", "status": "draft" }
}
```

---

### POST `/trips/{id}/share`

Tạo hoặc toggle link chia sẻ công khai.

**Auth required:** ✅

- Lần đầu gọi: tạo `share_token` và bật `is_public = true`
- Gọi lại: toggle `is_public`

**Response `200`**

```json
{
  "share_token": "aB3xY7...",
  "is_public": true,
  "share_url": "https://tripai.app/trips/share/aB3xY7..."
}
```

---

### GET `/trips/share/{token}`

Xem lịch trình công khai, **không cần đăng nhập**.

**Auth required:** Không

**Response `200`** — Cùng cấu trúc như `GET /trips/{id}`, nhưng không bao gồm `user_notes`.

**Response `404`** — Không tìm thấy hoặc chưa public

---

### PUT `/trips/{id}/notes`

Lưu ghi chú cá nhân của user cho lịch trình.

**Auth required:** ✅

**Request Body**

| Field | Type | Validation |
|-------|------|------------|
| `notes` | string\|null | max:10000 |

```json
{ "notes": "Đặt bàn trước ở Nhà hàng Bé Mặn, mang theo kem chống nắng" }
```

**Response `200`**

```json
{
  "message": "Đã lưu ghi chú.",
  "user_notes": "Đặt bàn trước ở Nhà hàng Bé Mặn..."
}
```

---

### GET `/trips/suggestions`

Gợi ý lịch trình từ users khác dựa trên sở thích.

**Auth required:** ✅

**Response `200`**

```json
{
  "suggestions": [
    {
      "id": 10,
      "destination": "Hội An",
      "duration_days": 3,
      "budget": "5000000.00",
      "preferences": ["culture", "food"],
      "start_date": "2024-05-20"
    }
  ]
}
```

---

### GET `/trips/{id}/packing-list`

AI tạo danh sách đồ cần mang dựa trên lịch trình.

**Auth required:** ✅

> Lịch trình phải có `status = completed`. Kết quả được cache 24 giờ.

**Response `200`**

```json
{
  "packing_list": {
    "categories": [
      {
        "name": "Quần áo",
        "emoji": "👕",
        "items": [
          { "name": "Áo phông", "quantity": "4 cái", "essential": true },
          { "name": "Áo tắm", "quantity": "2 cái", "essential": true, "note": "Cần cho bãi biển" }
        ]
      }
    ],
    "tips": [
      "Mang theo kem chống nắng SPF50+",
      "Đổi tiền mặt trước khi đến chợ"
    ]
  }
}
```

---

### GET `/trips/{id}/nearby`

Tìm địa điểm gần một tọa độ.

**Auth required:** ✅

**Query Params**

| Param | Type | Required | Mô tả |
|-------|------|----------|-------|
| `lat` | float | ✅ | Vĩ độ |
| `lng` | float | ✅ | Kinh độ |
| `type` | string | | `food` (default), `cafe`, `attraction`, `hotel` |

**Response `200`**

```json
{
  "places": [
    {
      "name": "Bún bò Huế Mợ Tôn",
      "type": "food",
      "distance_km": 0.3,
      "rating": 4.5,
      "address": "123 Lê Duẩn, Hải Châu"
    }
  ]
}
```

---

### GET `/trips/{id}/cost-split`

Tính toán chia chi phí theo số người.

**Auth required:** ✅

**Response `200`**

```json
{
  "num_people": 2,
  "total_estimated": 7400000,
  "total_actual": 0,
  "per_person_estimated": 3700000,
  "per_person_actual": 0,
  "breakdown": [
    {
      "category": "food",
      "label": "Ẩm thực",
      "emoji": "🍜",
      "total_estimated": 1200000,
      "total_actual": 0,
      "per_person_estimated": 600000,
      "per_person_actual": 0
    },
    {
      "category": "transport",
      "label": "Di chuyển",
      "emoji": "🚗",
      "total_estimated": 2000000,
      "total_actual": 0,
      "per_person_estimated": 1000000,
      "per_person_actual": 0
    }
  ]
}
```

---

## Trip Activities (Places)

Quản lý thủ công các hoạt động trong một ngày của lịch trình.

### POST `/trips/{tripId}/days/{dayId}/places`

Thêm hoạt động mới vào một ngày.

**Auth required:** ✅

**Request Body**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `time` | string | ✅ | format `HH:MM`, max:10 |
| `title` | string | ✅ | max:255 |
| `description` | string | | max:1000 |
| `place_name` | string | | max:255 |
| `place_type` | string | | `food` `cafe` `attraction` `hotel` `transport` `nightlife` `shopping` `other` |
| `estimated_cost` | number | | >= 0 |
| `duration_minutes` | integer | | >= 0 |
| `transport_to_next` | string | | max:255 |
| `distance_to_next_km` | number | | >= 0 |
| `latitude` | number | | |
| `longitude` | number | | |

```json
{
  "time": "19:00",
  "title": "Ăn tối tại nhà hàng hải sản",
  "place_name": "Nhà hàng Trần",
  "place_type": "food",
  "estimated_cost": 300000,
  "duration_minutes": 90,
  "latitude": 16.0544,
  "longitude": 108.2022
}
```

**Response `201`**

```json
{
  "place": {
    "id": 1002,
    "trip_day_id": 101,
    "trip_id": 42,
    "time": "19:00",
    "title": "Ăn tối tại nhà hàng hải sản",
    "place_name": "Nhà hàng Trần",
    "place_type": "food",
    "estimated_cost": "300000.00",
    "sort_order": 5
  }
}
```

---

### PUT `/trips/{tripId}/days/{dayId}/places/{placeId}`

Cập nhật hoạt động.

**Auth required:** ✅

**Request Body** — Các field giống `POST`, tất cả đều optional (`sometimes`)

**Response `200`**

```json
{ "place": { "id": 1002, "title": "Tên mới", "estimated_cost": "350000.00" } }
```

---

### DELETE `/trips/{tripId}/days/{dayId}/places/{placeId}`

Xóa hoạt động.

**Auth required:** ✅

**Response `204`** — No Content

---

## Budget

### PUT `/trips/{id}/budget/actual`

Cập nhật chi tiêu thực tế (sau khi đi về). Sẽ kích hoạt thông báo cảnh báo nếu >= 90% ngân sách dự kiến.

**Auth required:** ✅

**Request Body** — Tất cả optional, gửi field nào thì cập nhật field đó

| Field | Type | Validation |
|-------|------|------------|
| `food_actual` | number | >= 0 |
| `transport_actual` | number | >= 0 |
| `attraction_actual` | number | >= 0 |
| `accommodation_actual` | number | >= 0 |
| `other_actual` | number | >= 0 |

```json
{
  "food_actual": 1500000,
  "transport_actual": 2200000,
  "accommodation_actual": 2800000
}
```

**Response `200`**

```json
{
  "message": "Cập nhật chi tiêu thực tế thành công.",
  "budget": {
    "trip_id": 42,
    "food": "1200000.00",
    "food_actual": "1500000.00",
    "transport": "2000000.00",
    "transport_actual": "2200000.00",
    "total_estimated": "7400000.00",
    "total_actual": "6500000.00"
  }
}
```

---

## AI Chat

### POST `/trips/{id}/chat`

Gửi tin nhắn để chỉnh sửa lịch trình với AI. Giới hạn 50 tin/lịch trình.

**Auth required:** ✅  
**Rate limit:** 10 requests/phút (AI tier)

**Request Body**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `message` | string | ✅ | max:2000 |

```json
{
  "message": "Thêm một buổi tối xem pháo hoa vào ngày 2, sau 20:00"
}
```

**Response `200`**

```json
{
  "message": "Tôi đã thêm hoạt động xem pháo hoa vào 20:30 ngày 2...",
  "updated_timeline": {
    "days": [ ... ]
  },
  "suggestions": [
    "Đặt chỗ trước vì đông vào cuối tuần",
    "Nên đến sớm 30 phút để chọn vị trí đẹp"
  ],
  "chat_count": 3,
  "chat_limit": 50
}
```

> Nếu AI không thay đổi lịch trình, `updated_timeline` sẽ là `null`.

**Response `422`** — Lịch trình chưa hoàn thành

```json
{ "message": "Lịch trình chưa sẵn sàng để chỉnh sửa." }
```

**Response `429`** — Đạt giới hạn 50 tin

```json
{ "message": "Đã đạt giới hạn chỉnh sửa cho lịch trình này" }
```

---

## Saved Places

### POST `/places/save`

Lưu một địa điểm yêu thích.

**Auth required:** ✅

**Request Body**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `place_name` | string | ✅ | max:255 |
| `place_type` | string | | max:50 |
| `latitude` | number | | |
| `longitude` | number | | |

```json
{
  "place_name": "Bãi biển Mỹ Khê",
  "place_type": "attraction",
  "latitude": 16.0471,
  "longitude": 108.2478
}
```

**Response `201`**

```json
{
  "message": "Đã lưu địa điểm.",
  "place": {
    "id": 5,
    "user_id": 1,
    "place_name": "Bãi biển Mỹ Khê",
    "place_type": "attraction",
    "latitude": "16.0471000",
    "longitude": "108.2478000"
  }
}
```

---

### GET `/places/saved`

Danh sách địa điểm đã lưu (phân trang 20/trang).

**Auth required:** ✅

**Response `200`** — Laravel pagination

```json
{
  "current_page": 1,
  "data": [ { "id": 5, "place_name": "Bãi biển Mỹ Khê", "place_type": "attraction" } ],
  "total": 12
}
```

---

### DELETE `/places/saved/{id}`

Xóa địa điểm đã lưu.

**Auth required:** ✅

**Response `204`** — No Content

---

## Favorites

### POST `/trips/{id}/favorites`

Toggle yêu thích lịch trình (thêm hoặc bỏ).

**Auth required:** ✅

**Response `200`** — Đã thêm

```json
{ "message": "Đã thêm vào yêu thích.", "favorited": true }
```

**Response `200`** — Đã bỏ

```json
{ "message": "Đã bỏ yêu thích.", "favorited": false }
```

---

### GET `/favorites`

Danh sách lịch trình đã yêu thích (phân trang).

**Auth required:** ✅

**Response `200`** — Laravel pagination, mỗi item là Trip object kèm `budget_data`.

---

## Notifications

### GET `/notifications`

Danh sách thông báo (phân trang 20/trang, mới nhất trước).

**Auth required:** ✅

**Response `200`**

```json
{
  "current_page": 1,
  "data": [
    {
      "id": 10,
      "type": "trip_completed",
      "title": "Lịch trình sẵn sàng!",
      "body": "Lịch trình Đà Nẵng của bạn đã được tạo xong.",
      "data": { "trip_id": 42 },
      "read_at": null,
      "created_at": "2024-05-01T10:05:00.000000Z"
    }
  ],
  "total": 5
}
```

---

### GET `/notifications/unread-count`

Số thông báo chưa đọc.

**Auth required:** ✅

**Response `200`**

```json
{ "unread_count": 3 }
```

---

### PUT `/notifications/{id}/read`

Đánh dấu một thông báo đã đọc.

**Auth required:** ✅

**Response `200`**

```json
{ "message": "Đã đánh dấu đã đọc." }
```

---

### PUT `/notifications/read-all`

Đánh dấu tất cả thông báo đã đọc.

**Auth required:** ✅

**Response `200`**

```json
{ "message": "Đã đánh dấu tất cả đã đọc." }
```

---

## Activity Logs

### GET `/activity-logs`

50 log hoạt động gần nhất của user.

**Auth required:** ✅

**Response `200`**

```json
{
  "logs": [
    {
      "id": 100,
      "user_id": 1,
      "action": "create_trip",
      "description": "Tạo lịch trình đến Đà Nẵng",
      "ip_address": "127.0.0.1",
      "user_agent": "Mozilla/5.0...",
      "created_at": "2024-05-01T10:00:00.000000Z"
    }
  ]
}
```

**Các giá trị `action`**

| Value | Ý nghĩa |
|-------|---------|
| `login` | Đăng nhập |
| `logout` | Đăng xuất |
| `create_trip` | Tạo lịch trình |
| `delete_trip` | Xóa lịch trình |
| `update_profile` | Cập nhật hồ sơ |

---

## Utility

### GET `/health`

Health check (không cần auth).

**Response `200`**

```json
{
  "status": "ok",
  "app": "TripAI",
  "env": "production"
}
```

---

## Rate Limiting

| Nhóm | Giới hạn | Áp dụng cho |
|------|----------|-------------|
| `api` (standard) | 60 requests/phút | Tất cả route được bảo vệ |
| `ai` (strict) | 10 requests/phút | `POST /trips`, `POST /trips/{id}/chat` |

Khi vượt quá giới hạn, server trả `429 Too Many Requests`.

---

## Error Responses

| HTTP Code | Ý nghĩa |
|-----------|---------|
| `400` | Bad Request — payload không hợp lệ |
| `401` | Unauthorized — thiếu hoặc sai JWT token |
| `403` | Forbidden — không có quyền truy cập resource |
| `404` | Not Found — resource không tồn tại |
| `422` | Unprocessable Entity — validation thất bại |
| `429` | Too Many Requests — vượt rate limit hoặc chat limit |
| `500` | Internal Server Error |

**Cấu trúc lỗi validation (`422`)**

```json
{
  "message": "The destination field is required.",
  "errors": {
    "destination": ["Vui lòng nhập điểm đến."],
    "start_date": ["Ngày bắt đầu phải từ hôm nay trở đi."]
  }
}
```

**Cấu trúc lỗi thông thường**

```json
{ "message": "Bạn không có quyền truy cập tài nguyên này" }
```

---

## Data Models

### Trip

```
id              integer
user_id         integer
destination     string
origin          string|null
destination_lat decimal(10,7)|null
destination_lng decimal(10,7)|null
start_date      date
duration_days   integer
budget          decimal(15,2)        — ngân sách kế hoạch người dùng nhập
num_people      integer
travel_type     solo|couple|family|group|null
transport_mode  string|null
accommodation_type hotel|homestay|hostel|resort|airbnb|villa|other|null
accommodation_area string|null
arrival_time    HH:MM|null
preferences     string[]
notes           string|null          — ghi chú khi tạo trip
user_notes      string|null          — ghi chú cá nhân sau khi đi
status          processing|completed|failed|draft
share_token     string|null
is_public       boolean
created_at      timestamp
updated_at      timestamp
```

### TripDay

```
id          integer
trip_id     integer
day_number  integer
date        date
weather     object|null
  summary           string
  icon              string        — OpenWeather icon code (01d, 02d...)
  temperature_high  number
  temperature_low   number
  rain_probability  float         — 0.0–1.0
```

### TripPlace (Activity)

```
id                   integer
trip_day_id          integer
trip_id              integer
time                 string        — HH:MM
title                string
description          string|null
place_name           string|null
place_type           food|cafe|attraction|hotel|transport|nightlife|shopping|other|null
estimated_cost       decimal(10,2)
duration_minutes     integer
transport_to_next    string|null
distance_to_next_km  decimal(5,2)|null
latitude             decimal(10,7)|null
longitude            decimal(10,7)|null
sort_order           integer
```

### TripBudget

```
trip_id               integer
food                  decimal(15,2)   — chi phí ăn uống dự kiến
transport             decimal(15,2)
attraction            decimal(15,2)
accommodation         decimal(15,2)
other                 decimal(15,2)
total_estimated       decimal(15,2)
food_actual           decimal(15,2)   — chi tiêu thực tế
transport_actual      decimal(15,2)
attraction_actual     decimal(15,2)
accommodation_actual  decimal(15,2)
other_actual          decimal(15,2)
total_actual          decimal(15,2)
```

### Notification

```
id          integer
user_id     integer
type        string        — trip_completed|trip_failed|budget_warning|...
title       string
body        string
data        object|null   — metadata tùy theo type (vd: trip_id)
read_at     timestamp|null
created_at  timestamp
```

### User

```
id          integer
name        string
email       string
avatar      string|null   — URL ảnh đại diện (Cloudinary hoặc local)
phone       string|null
bio         string|null
created_at  timestamp
```
