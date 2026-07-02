import hashlib
import hmac
import os
import secrets
import shutil
import sqlite3
from datetime import datetime, timedelta, timezone
from pathlib import Path

import jwt
from fastapi import Depends, FastAPI, File, Form, Header, HTTPException, Request, UploadFile
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import HTMLResponse, JSONResponse
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from pydantic import BaseModel, Field


APP_TITLE = "Mini Bug Bounty Lab - Fixed"
DB_PATH = os.getenv("DB_PATH", "lab.db")
JWT_SECRET = os.getenv("JWT_SECRET", "change-me-in-compose-9f4d3222cb8a4a8c8d4bb4e38e7aa23b")
JWT_ALG = "HS256"
ALLOWED_ORIGINS = [origin.strip() for origin in os.getenv("ALLOWED_ORIGINS", "http://localhost:8001").split(",")]
ALLOWED_EXTENSIONS = {".png", ".jpg", ".jpeg", ".gif"}
ALLOWED_MIME = {"image/png", "image/jpeg", "image/gif"}
MAX_UPLOAD_SIZE = 1_000_000
LOGIN_ATTEMPTS: dict[str, list[datetime]] = {}

app = FastAPI(title=APP_TITLE, version="1.0.0-fixed")
templates = Jinja2Templates(directory="templates")
app.mount("/uploads", StaticFiles(directory="uploads"), name="uploads")

app.add_middleware(
    CORSMiddleware,
    allow_origins=ALLOWED_ORIGINS,
    allow_credentials=True,
    allow_methods=["GET", "POST", "PUT"],
    allow_headers=["Authorization", "Content-Type"],
)


class RegisterIn(BaseModel):
    username: str = Field(min_length=3, max_length=40)
    password: str = Field(min_length=8, max_length=128)
    email: str = Field(max_length=120)
    full_name: str = Field(default="", max_length=120)


class LoginIn(BaseModel):
    username: str
    password: str


class ProfileUpdate(BaseModel):
    email: str | None = Field(default=None, max_length=120)
    full_name: str | None = Field(default=None, max_length=120)


class FeedbackIn(BaseModel):
    message: str = Field(min_length=1, max_length=1000)


class CheckoutIn(BaseModel):
    product_id: int
    quantity: int = Field(default=1, ge=1, le=20)


def db():
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    return conn


def row_to_dict(row):
    return dict(row) if row else None


def hash_password(password: str, salt: str | None = None) -> str:
    salt = salt or secrets.token_hex(16)
    digest = hashlib.pbkdf2_hmac("sha256", password.encode(), salt.encode(), 120_000).hex()
    return f"{salt}${digest}"


def verify_password(password: str, stored: str) -> bool:
    salt, expected = stored.split("$", 1)
    actual = hashlib.pbkdf2_hmac("sha256", password.encode(), salt.encode(), 120_000).hex()
    return hmac.compare_digest(actual, expected)


def create_token(user):
    payload = {
        "sub": str(user["id"]),
        "username": user["username"],
        "role": user["role"],
        "exp": datetime.now(timezone.utc) + timedelta(hours=2),
        "iat": datetime.now(timezone.utc),
    }
    return jwt.encode(payload, JWT_SECRET, algorithm=JWT_ALG)


def current_user(authorization: str | None = Header(default=None)):
    if not authorization or not authorization.lower().startswith("bearer "):
        raise HTTPException(status_code=401, detail="Missing bearer token")
    token = authorization.split(" ", 1)[1]
    try:
        payload = jwt.decode(token, JWT_SECRET, algorithms=[JWT_ALG], options={"require": ["exp", "sub", "role"]})
    except jwt.PyJWTError:
        raise HTTPException(status_code=401, detail="Invalid token")
    conn = db()
    user = conn.execute("SELECT * FROM users WHERE id = ?", (payload["sub"],)).fetchone()
    conn.close()
    if not user:
        raise HTTPException(status_code=401, detail="User not found")
    user = row_to_dict(user)
    if user["role"] != payload.get("role"):
        raise HTTPException(status_code=401, detail="Invalid token claims")
    return user


def require_role(*roles: str):
    def checker(user=Depends(current_user)):
        if user["role"] not in roles:
            raise HTTPException(status_code=403, detail="Insufficient role")
        return user
    return checker


@app.exception_handler(Exception)
async def safe_exception_handler(request: Request, exc: Exception):
    return JSONResponse(status_code=500, content={"detail": "Internal server error"})


def init_db():
    conn = db()
    conn.executescript(
        """
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            email TEXT NOT NULL,
            full_name TEXT,
            role TEXT NOT NULL CHECK(role IN ('user','staff','admin')),
            avatar TEXT
        );
        CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            item TEXT NOT NULL,
            amount REAL NOT NULL,
            status TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            price REAL NOT NULL
        );
        CREATE TABLE IF NOT EXISTS feedback (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            message TEXT NOT NULL,
            created_at TEXT NOT NULL
        );
        """
    )
    count = conn.execute("SELECT COUNT(*) AS c FROM users").fetchone()["c"]
    if count == 0:
        users = [
            ("alice", hash_password("alice12345"), "alice@example.local", "Alice Nguyen", "user"),
            ("bob", hash_password("bob12345"), "bob@example.local", "Bob Tran", "user"),
            ("staff", hash_password("staff12345"), "staff@example.local", "Support Staff", "staff"),
            ("admin", hash_password("admin12345"), "admin@example.local", "Lab Admin", "admin"),
        ]
        conn.executemany(
            "INSERT INTO users(username,password_hash,email,full_name,role) VALUES(?,?,?,?,?)",
            users,
        )
        conn.executemany(
            "INSERT INTO products(name,price) VALUES(?,?)",
            [("Wireless Mouse", 19.99), ("USB-C Hub", 39.99), ("Pentest Notebook", 12.5)],
        )
        conn.executemany(
            "INSERT INTO orders(user_id,item,amount,status) VALUES(?,?,?,?)",
            [
                (1, "Wireless Mouse", 19.99, "paid"),
                (1, "Pentest Notebook", 12.5, "shipped"),
                (2, "USB-C Hub", 39.99, "processing"),
                (2, "Wireless Mouse", 19.99, "paid"),
            ],
        )
        conn.execute(
            "INSERT INTO feedback(user_id,message,created_at) VALUES(?,?,?)",
            (1, "Great shop, fast delivery.", datetime.utcnow().isoformat()),
        )
    conn.commit()
    conn.close()


@app.on_event("startup")
def startup():
    os.makedirs("uploads", exist_ok=True)
    init_db()


@app.get("/", response_class=HTMLResponse)
def home(request: Request):
    return templates.TemplateResponse("index.html", {"request": request, "title": APP_TITLE})


def web_app(request: Request):
    return templates.TemplateResponse(
        "app.html",
        {
            "request": request,
            "title": APP_TITLE,
            "mode": "fixed",
            "login_hint": "alice / alice12345",
            "checkout_allows_amount": False,
        },
    )


@app.get("/login", response_class=HTMLResponse)
def login_page(request: Request):
    return web_app(request)


@app.get("/register", response_class=HTMLResponse)
def register_page(request: Request):
    return web_app(request)


@app.get("/dashboard", response_class=HTMLResponse)
def dashboard_page(request: Request):
    return web_app(request)


@app.get("/profile", response_class=HTMLResponse)
def profile_page(request: Request):
    return web_app(request)


@app.get("/orders", response_class=HTMLResponse)
def orders_page(request: Request):
    return web_app(request)


@app.get("/orders/{order_id}/view", response_class=HTMLResponse)
def order_detail_page(request: Request, order_id: int):
    return web_app(request)


@app.get("/avatar", response_class=HTMLResponse)
def avatar_page(request: Request):
    return web_app(request)


@app.get("/feedback", response_class=HTMLResponse)
def feedback_page(request: Request):
    return web_app(request)


@app.get("/admin", response_class=HTMLResponse)
def admin_page(request: Request):
    return web_app(request)


@app.get("/checkout", response_class=HTMLResponse)
def checkout_page(request: Request):
    return web_app(request)


@app.get("/feedback-wall", response_class=HTMLResponse)
def feedback_wall(request: Request):
    conn = db()
    items = conn.execute(
        "SELECT feedback.*, users.username FROM feedback JOIN users ON users.id = feedback.user_id ORDER BY feedback.id DESC"
    ).fetchall()
    conn.close()
    return templates.TemplateResponse("feedback.html", {"request": request, "items": items})


@app.post("/api/register")
def register(data: RegisterIn):
    conn = db()
    try:
        cur = conn.execute(
            "INSERT INTO users(username,password_hash,email,full_name,role) VALUES(?,?,?,?,?)",
            (data.username, hash_password(data.password), data.email, data.full_name, "user"),
        )
        conn.commit()
        return {"id": cur.lastrowid, "username": data.username, "role": "user"}
    except sqlite3.IntegrityError:
        raise HTTPException(status_code=409, detail="Username already exists")
    finally:
        conn.close()


def too_many_logins(username: str) -> bool:
    now = datetime.now(timezone.utc)
    window_start = now - timedelta(minutes=1)
    attempts = [ts for ts in LOGIN_ATTEMPTS.get(username, []) if ts > window_start]
    LOGIN_ATTEMPTS[username] = attempts
    return len(attempts) >= 5


@app.post("/api/login")
def login(data: LoginIn):
    if too_many_logins(data.username):
        raise HTTPException(status_code=429, detail="Too many login attempts. Try again later.")
    conn = db()
    user = conn.execute("SELECT * FROM users WHERE username = ?", (data.username,)).fetchone()
    conn.close()
    if not user or not verify_password(data.password, user["password_hash"]):
        LOGIN_ATTEMPTS.setdefault(data.username, []).append(datetime.now(timezone.utc))
        raise HTTPException(status_code=401, detail="Invalid username or password")
    LOGIN_ATTEMPTS.pop(data.username, None)
    return {"access_token": create_token(user), "token_type": "bearer", "role": user["role"]}


@app.post("/api/logout")
def logout():
    return {"message": "Client should delete the token"}


@app.get("/api/me")
def me(user=Depends(current_user)):
    return {"id": user["id"], "username": user["username"], "email": user["email"], "full_name": user["full_name"], "role": user["role"], "avatar": user["avatar"]}


@app.put("/api/me")
def update_me(data: ProfileUpdate, user=Depends(current_user)):
    conn = db()
    conn.execute(
        "UPDATE users SET email = COALESCE(?, email), full_name = COALESCE(?, full_name) WHERE id = ?",
        (data.email, data.full_name, user["id"]),
    )
    conn.commit()
    conn.close()
    return {"message": "profile updated"}


@app.get("/api/profile/{user_id}")
def get_profile(user_id: int, user=Depends(current_user)):
    if user["id"] != user_id and user["role"] != "admin":
        raise HTTPException(status_code=403, detail="Cannot access another user's profile")
    conn = db()
    profile = conn.execute("SELECT id,username,email,full_name,role,avatar FROM users WHERE id = ?", (user_id,)).fetchone()
    conn.close()
    if not profile:
        raise HTTPException(status_code=404, detail="Profile not found")
    return row_to_dict(profile)


@app.put("/api/profile/{user_id}")
def update_profile(user_id: int, data: ProfileUpdate, user=Depends(current_user)):
    if user["id"] != user_id and user["role"] != "admin":
        raise HTTPException(status_code=403, detail="Cannot update another user's profile")
    conn = db()
    conn.execute(
        "UPDATE users SET email = COALESCE(?, email), full_name = COALESCE(?, full_name) WHERE id = ?",
        (data.email, data.full_name, user_id),
    )
    conn.commit()
    conn.close()
    return {"message": f"profile {user_id} updated"}


@app.get("/api/orders")
def list_orders(user=Depends(current_user)):
    conn = db()
    rows = conn.execute("SELECT * FROM orders WHERE user_id = ?", (user["id"],)).fetchall()
    conn.close()
    return [row_to_dict(row) for row in rows]


@app.get("/api/orders/{order_id}")
def order_detail(order_id: int, user=Depends(current_user)):
    conn = db()
    if user["role"] == "admin":
        order = conn.execute("SELECT * FROM orders WHERE id = ?", (order_id,)).fetchone()
    else:
        order = conn.execute("SELECT * FROM orders WHERE id = ? AND user_id = ?", (order_id, user["id"])).fetchone()
    conn.close()
    if not order:
        raise HTTPException(status_code=404, detail="Order not found")
    return row_to_dict(order)


@app.post("/api/avatar")
def upload_avatar(file: UploadFile = File(...), user=Depends(current_user)):
    ext = Path(file.filename or "").suffix.lower()
    if ext not in ALLOWED_EXTENSIONS or file.content_type not in ALLOWED_MIME:
        raise HTTPException(status_code=400, detail="Only png, jpg, jpeg and gif images are allowed")
    filename = f"{secrets.token_hex(16)}{ext}"
    dst = os.path.join("uploads", filename)
    size = 0
    with open(dst, "wb") as out:
        while chunk := file.file.read(64 * 1024):
            size += len(chunk)
            if size > MAX_UPLOAD_SIZE:
                out.close()
                os.remove(dst)
                raise HTTPException(status_code=413, detail="File too large")
            out.write(chunk)
    conn = db()
    conn.execute("UPDATE users SET avatar = ? WHERE id = ?", (f"/uploads/{filename}", user["id"]))
    conn.commit()
    conn.close()
    return {"avatar_url": f"/uploads/{filename}", "content_type": file.content_type, "size": size}


@app.post("/api/feedback")
def create_feedback(data: FeedbackIn, user=Depends(current_user)):
    conn = db()
    conn.execute(
        "INSERT INTO feedback(user_id,message,created_at) VALUES(?,?,?)",
        (user["id"], data.message, datetime.utcnow().isoformat()),
    )
    conn.commit()
    conn.close()
    return {"message": "feedback stored"}


@app.get("/api/feedback")
def search_feedback(search: str = "", user=Depends(current_user)):
    conn = db()
    rows = conn.execute(
        "SELECT feedback.*, users.username FROM feedback JOIN users ON users.id = feedback.user_id WHERE message LIKE ?",
        (f"%{search}%",),
    ).fetchall()
    conn.close()
    return [row_to_dict(row) for row in rows]


@app.get("/api/admin/users")
def admin_users(search: str = "", user=Depends(require_role("admin"))):
    conn = db()
    rows = conn.execute(
        "SELECT id,username,email,full_name,role,avatar FROM users WHERE username LIKE ? OR email LIKE ?",
        (f"%{search}%", f"%{search}%"),
    ).fetchall()
    conn.close()
    return [row_to_dict(row) for row in rows]


@app.post("/api/checkout")
def checkout(data: CheckoutIn, user=Depends(current_user)):
    conn = db()
    product = conn.execute("SELECT * FROM products WHERE id = ?", (data.product_id,)).fetchone()
    if not product:
        conn.close()
        raise HTTPException(status_code=404, detail="Product not found")
    total = round(float(product["price"]) * data.quantity, 2)
    item = f"{product['name']} x{data.quantity}"
    cur = conn.execute(
        "INSERT INTO orders(user_id,item,amount,status) VALUES(?,?,?,?)",
        (user["id"], item, total, "paid"),
    )
    conn.commit()
    conn.close()
    return {"order_id": cur.lastrowid, "item": item, "charged_amount": total}
