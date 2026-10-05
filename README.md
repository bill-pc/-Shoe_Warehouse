
# 👟 Shoe Warehouse Management System

Hệ thống quản lý kho giày thông minh tích hợp AI (CLIP Model) tìm kiếm theo hình ảnh và gợi ý sắp xếp kệ kho tự động.

---

## 📂 Cấu Trúc Thư Mục

```text
Shoe_Warehouse/
├── .env.example            # Cấu hình mẫu biến môi trường
├── .gitignore              # Danh sách loại trừ file khỏi Git
├── README.md               # Hướng dẫn dự án
├── testapi.php             # File test API
├── testhash.php            # File test mã hóa mật khẩu
│
├── ai_services/            # Dịch vụ AI (Python)
│   ├── ai_bridge.py        # API Chatbot (Port 8000)
│   ├── api_vector.py       # API Quét ảnh (Port 5000)
│   ├── generate_vector.py  # Script khởi tạo / Tải CLIP Model
│   ├── VisionService.php   # Cầu nối gọi Python từ PHP
│   └── models/             # Thư mục lưu bộ não AI (CLIP Model)
│
├── app/                    # Source code PHP chính (MVC)
│   ├── controllers/        # Auth, Category, Product, Report, User Controllers
│   ├── models/             # Category, Product, Report, User Models
│   └── views/              # Layouts & Pages
│
├── config/                 # Cấu hình kết nối hệ thống (database.php)
├── csdl/                   # File sao lưu dữ liệu (backup.sql)
└── public/                 # Thư mục thực thi chính
    ├── index.php
    └── assets/             # CSS, Hình sản phẩm, Logo, Temp

```

---

## 🛠️ Cài Đặt & Cấu Hình

### 1. Cài đặt Python & AI Model

```bash
# Cài đặt thư viện CLIP trực tiếp từ source OpenAI
python -m pip install git+[https://github.com/openai/CLIP.git](https://github.com/openai/CLIP.git)

# Khởi tạo và tải bộ não CLIP Model (Chạy 1 lần duy nhất)
cd ai_services
python generate_vector.py

```

### 2. Cấu hình CSDL PostgreSQL

1. Tạo Database tên: `shoe_warehouse_ai`.
2. Import file `csdl/backup.sql` vào database vừa tạo.
3. Kích hoạt extension pgvector hỗ trợ tìm kiếm vector:
```sql
CREATE EXTENSION IF NOT EXISTS vector;

```


4. Cấu hình thông tin kết nối CSDL trong `config/database.php` hoặc sao chép `.env.example` thành `.env` để chỉnh sửa.

### 3. Cài đặt PHP Dependencies

```bash
composer install

```

---

## 🚀 Lệnh SQL Quản Lý Kệ Kho (Chạy trong Query Tool)

### 🧹 1. Xóa toàn bộ giày khỏi Layout kệ

```sql
DO $$
DECLARE
    r RECORD;
    t_key TEXT;
    s_key TEXT;
    new_layout JSONB;
BEGIN
    FOR r IN SELECT shelf_id, layout FROM shelves LOOP
        new_layout := r.layout;
        FOR t_key IN SELECT jsonb_object_keys(r.layout) LOOP
            FOR s_key IN SELECT jsonb_object_keys(r.layout->t_key) LOOP
                new_layout := jsonb_set(new_layout, ARRAY[t_key, s_key], '[]'::jsonb);
            END LOOP;
        END LOOP;
        
        UPDATE shelves SET layout = new_layout WHERE shelf_id = r.shelf_id;
    END LOOP;
    
    RAISE NOTICE 'Đã xóa toàn bộ giày trên các kệ thành công!';
END $$;

```

### 📥 2. Tự động Import giày vào kệ theo ưu tiên

```sql
DO $$
DECLARE
    v_tier_priority INT[] := ARRAY[2, 1, 3, 4]; -- Ưu tiên: Tầm tay (2) -> Hông (1) -> Mắt (3) -> Leo tầng (4)
    v_shelves TEXT[] := ARRAY['A', 'B', 'C', 'D', 'E', 'F'];

    v_vid INT;
    v_shelf_name TEXT;
    v_layout JSONB;
    v_t INT;
    v_s INT;
    v_has_more BOOLEAN;
    v_top_brand_ids INT[];
BEGIN
    -- 1. RESET KHO
    UPDATE shelves SET layout = '{
        "1":{"01":[],"02":[],"03":[],"04":[],"05":[],"06":[]},
        "2":{"01":[],"02":[],"03":[],"04":[],"05":[],"06":[]},
        "3":{"01":[],"02":[],"03":[],"04":[],"05":[],"06":[]},
        "4":{"01":[],"02":[],"03":[],"04":[],"05":[],"06":[]}
    }'::jsonb;

    -- 2. XÁC ĐỊNH TOP 2 BRAND BÁN CHẠY
    SELECT ARRAY_AGG(category_id) INTO v_top_brand_ids
    FROM (
        SELECT p.category_id, SUM(COALESCE(t.quantity, 0)) as vol
        FROM categories c
        JOIN products p ON c.category_id = p.category_id
        JOIN product_variants pv ON p.product_id = pv.product_id
        LEFT JOIN transactions t ON pv.variant_id = t.variant_id
        GROUP BY p.category_id
        ORDER BY vol DESC LIMIT 2
    ) sub;

    -- 3. TẠO QUEUE HÀNG ĐỜI
    CREATE TEMP TABLE queue_top AS
    SELECT pv.variant_id
    FROM product_variants pv
    JOIN products p ON pv.product_id = p.product_id
    LEFT JOIN (SELECT variant_id, SUM(quantity) as v_vol FROM transactions GROUP BY 1) va ON pv.variant_id = va.variant_id
    CROSS JOIN generate_series(1, pv.stock)
    WHERE p.category_id = ANY(v_top_brand_ids) AND pv.is_deleted = false AND pv.stock > 0
    ORDER BY p.category_id, va.v_vol DESC;

    CREATE TEMP TABLE queue_others AS
    SELECT pv.variant_id
    FROM product_variants pv
    JOIN products p ON pv.product_id = p.product_id
    LEFT JOIN (SELECT variant_id, SUM(quantity) as v_vol FROM transactions GROUP BY 1) va ON pv.variant_id = va.variant_id
    CROSS JOIN generate_series(1, pv.stock)
    WHERE p.category_id != ALL(v_top_brand_ids) AND pv.is_deleted = false AND pv.stock > 0
    ORDER BY p.category_id, va.v_vol DESC;

    -- 4. ĐỔ TOP 2 BRAND THEO CHIỀU DỌC (VERTICAL)
    DECLARE
        hot_cursor CURSOR FOR SELECT variant_id FROM queue_top;
    BEGIN
        OPEN hot_cursor;
        v_has_more := TRUE;
        FOREACH v_shelf_name IN ARRAY v_shelves LOOP
            IF NOT v_has_more THEN EXIT; END IF;
            SELECT layout INTO v_layout FROM shelves WHERE shelf_name = v_shelf_name;

            FOREACH v_t IN ARRAY v_tier_priority LOOP
                FOR v_s IN 1..6 LOOP
                    FOR i IN 1..4 LOOP
                        FETCH hot_cursor INTO v_vid;
                        IF NOT FOUND THEN v_has_more := FALSE; EXIT; END IF;
                        v_layout := jsonb_set(v_layout, ARRAY[v_t::text, LPAD(v_s::text, 2, '0')], (v_layout->v_t::text->LPAD(v_s::text, 2, '0')) || to_jsonb(v_vid));
                    END LOOP;
                    IF NOT v_has_more THEN EXIT; END IF;
                END LOOP;
                IF NOT v_has_more THEN EXIT; END IF;
            END LOOP;
            UPDATE shelves SET layout = v_layout WHERE shelf_name = v_shelf_name;
        END LOOP;
        CLOSE hot_cursor;
    END;

    -- 5. ĐỔ CÁC HÃNG CÒN LẠI THEO CHIỀU NGANG (HORIZONTAL SPREADING)
    DECLARE
        others_cursor CURSOR FOR SELECT variant_id FROM queue_others;
    BEGIN
        OPEN others_cursor;
        v_has_more := TRUE;
        FOREACH v_t IN ARRAY v_tier_priority LOOP
            FOREACH v_shelf_name IN ARRAY v_shelves LOOP
                SELECT layout INTO v_layout FROM shelves WHERE shelf_name = v_shelf_name;

                FOR v_s IN 1..6 LOOP
                    WHILE jsonb_array_length(v_layout->v_t::text->LPAD(v_s::text, 2, '0')) < 4 LOOP
                        FETCH others_cursor INTO v_vid;
                        IF NOT FOUND THEN 
                            UPDATE shelves SET layout = v_layout WHERE shelf_name = v_shelf_name;
                            v_has_more := FALSE;
                            EXIT; 
                        END IF;
                        v_layout := jsonb_set(v_layout, ARRAY[v_t::text, LPAD(v_s::text, 2, '0')], (v_layout->v_t::text->LPAD(v_s::text, 2, '0')) || to_jsonb(v_vid));
                    END LOOP;
                    IF NOT v_has_more THEN EXIT; END IF;
                END LOOP;
                UPDATE shelves SET layout = v_layout WHERE shelf_name = v_shelf_name;
                IF NOT v_has_more THEN EXIT; END IF;
            END LOOP;
            IF NOT v_has_more THEN EXIT; END IF;
        END LOOP;
        CLOSE others_cursor;
    END;

    DROP TABLE queue_top;
    DROP TABLE queue_others;
    RAISE NOTICE 'Hoàn tất! Top 2 Brand đã lấp đầy kệ đầu, các hãng khác dàn đều kệ cuối.';
END $$;

```

---

## 🌐 Hướng Dẫn Cấu Hình Ngrok (Public Port Demo)

1. Tải Ngrok từ trang chủ: [ngrok.com/download](https://ngrok.com/download)
2. Giải nén file `ngrok.exe` và copy vào thư mục `C:\Windows\` để gọi lệnh ngrok từ mọi nơi.
3. Đăng nhập [dashboard.ngrok.com](https://www.google.com/search?q=https://dashboard.ngrok.com) lấy Authtoken và kích hoạt:
```bash
ngrok config add-authtoken <YOUR_AUTHTOKEN>

```


4. Mở port web server (ví dụ port 80 cho XAMPP):
```bash
ngrok http 80

```



```

---


