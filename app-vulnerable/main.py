import hashlib
import os
import shutil
import sqlite3
import traceback
from datetime import datetime, timedelta, timezone

import jwt
from fastapi import Depends, FastAPI, File, Form, Header, HTTPException, Request, UploadFile
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import HTMLResponse, JSONResponse
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from pydantic import BaseModel


APP_TITLE = "Mini Bug Bounty Lab - Vulnerable"
DB_PATH = os.getenv("DB_PATH", "lab.db")
JWT_SECRET = "secret"
JWT_ALG = "HS256"

app = FastAPI(title=APP_TITLE, version="1.0.0-vulnerable")
templates = Jinja2Templates(directory="templates")
app.mount("/uploads", StaticFiles(directory="uploads"), name="uploads")

# Vulnerable on purpose: any origin is accepted.
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


class RegisterIn(BaseModel):
    username: str
    password: str
    email: str
    full_name: str = ""


class LoginIn(BaseModel):
    username: str
    password: str


class ProfileUpdate(BaseModel):
    email: str | None = None
    full_name: str | None = None


class FeedbackIn(BaseModel):
    message: str


class CheckoutIn(BaseModel):
    product_id: int
    quantity: int = 1
    amount: float


def db():
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    return conn


def row_to_dict(row):
    return dict(row) if row else None


def hash_password(password: str) -> str:
    return hashlib.sha256(password.encode()).hexdigest()


def create_token(user):
    payload = {
        "sub": str(user["id"]),
        "username": user["username"],
        "role": user["role"],
        "exp": datetime.now(timezone.utc) + timedelta(hours=8),
    }
    return jwt.encode(payload, JWT_SECRET, algorithm=JWT_ALG)


def current_user(authorization: str | None = Header(default=None)):
    if not authorization or not authorization.lower().startswith("bearer "):
        raise HTTPException(status_code=401, detail="Missing bearer token")
    token = authorization.split(" ", 1)[1]
    try:
        payload = jwt.decode(token, JWT_SECRET, algorithms=[JWT_ALG])
    except Exception as exc:
        raise HTTPException(status_code=401, detail=f"Invalid token: {exc}")
    conn = db()
    user = conn.execute("SELECT * FROM users WHERE id = ?", (payload["sub"],)).fetchone()
    conn.close()
    if not user:
        raise HTTPException(status_code=401, detail="User not found")
    return row_to_dict(user)


@app.exception_handler(Exception)
async def debug_exception_handler(request: Request, exc: Exception):
    # Vulnerable on purpose: exposes stack trace and internal details.
    return JSONResponse(
        status_code=500,
        content={"error": str(exc), "trace": traceback.format_exc(), "db_path": DB_PATH},
    )


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
            role TEXT NOT NULL,
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
            ("alice", hash_password("alice123"), "alice@example.local", "Alice Nguyen", "user"),
            ("bob", hash_password("bob123"), "bob@example.local", "Bob Tran", "user"),
            ("staff", hash_password("staff123"), "staff@example.local", "Support Staff", "staff"),
            ("admin", hash_password("admin123"), "admin@example.local", "Lab Admin", "admin"),
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
    finally:
        conn.close()


@app.post("/api/login")
def login(data: LoginIn):
    # Vulnerable on purpose: no rate limit or account lockout.
    conn = db()
    user = conn.execute("SELECT * FROM users WHERE username = ?", (data.username,)).fetchone()
    conn.close()
    if not user or user["password_hash"] != hash_password(data.password):
        raise HTTPException(status_code=401, detail="Invalid username or password")
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
    # Vulnerable on purpose: authenticated users can read any profile by changing ID.
    conn = db()
    profile = conn.execute("SELECT id,username,email,full_name,role,avatar FROM users WHERE id = ?", (user_id,)).fetchone()
    conn.close()
    if not profile:
        raise HTTPException(status_code=404, detail="Profile not found")
    return row_to_dict(profile)


@app.put("/api/profile/{user_id}")
def update_profile(user_id: int, data: ProfileUpdate, user=Depends(current_user)):
    # Vulnerable on purpose: authenticated users can update another user's profile.
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
    # Vulnerable on purpose: missing owner check creates IDOR/BOLA.
    conn = db()
    order = conn.execute("SELECT * FROM orders WHERE id = ?", (order_id,)).fetchone()
    conn.close()
    if not order:
        raise HTTPException(status_code=404, detail="Order not found")
    return row_to_dict(order)


@app.post("/api/avatar")
def upload_avatar(file: UploadFile = File(...), user=Depends(current_user)):
    # Vulnerable on purpose: trusts client filename and content type.
    dst = os.path.join("uploads", file.filename)
    with open(dst, "wb") as out:
        shutil.copyfileobj(file.file, out)
    conn = db()
    conn.execute("UPDATE users SET avatar = ? WHERE id = ?", (f"/uploads/{file.filename}", user["id"]))
    conn.commit()
    conn.close()
    return {"avatar_url": f"/uploads/{file.filename}", "content_type": file.content_type}


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
    # Vulnerable on purpose: SQL injection through string interpolation.
    conn = db()
    query = f"SELECT feedback.*, users.username FROM feedback JOIN users ON users.id = feedback.user_id WHERE message LIKE '%{search}%'"
    rows = conn.execute(query).fetchall()
    conn.close()
    return [row_to_dict(row) for row in rows]


@app.get("/api/admin/users")
def admin_users(search: str = "", user=Depends(current_user)):
    # Vulnerable on purpose: no role check and injectable search.
    conn = db()
    query = f"SELECT id,username,email,full_name,role,avatar FROM users WHERE username LIKE '%{search}%' OR email LIKE '%{search}%'"
    rows = conn.execute(query).fetchall()
    conn.close()
    return [row_to_dict(row) for row in rows]


@app.post("/api/checkout")
def checkout(data: CheckoutIn, user=Depends(current_user)):
    # Vulnerable on purpose: trusts amount supplied by the client.
    conn = db()
    product = conn.execute("SELECT * FROM products WHERE id = ?", (data.product_id,)).fetchone()
    if not product:
        conn.close()
        raise HTTPException(status_code=404, detail="Product not found")
    item = f"{product['name']} x{data.quantity}"
    cur = conn.execute(
        "INSERT INTO orders(user_id,item,amount,status) VALUES(?,?,?,?)",
        (user["id"], item, data.amount, "paid"),
    )
    conn.commit()
    conn.close()
    return {"order_id": cur.lastrowid, "item": item, "charged_amount": data.amount}
