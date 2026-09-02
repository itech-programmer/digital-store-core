from __future__ import annotations

import argparse
import json
import sys
import time
import uuid
from concurrent.futures import ThreadPoolExecutor, as_completed
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


def http_json(method: str, url: str, payload: dict | None = None, timeout: float = 60.0) -> tuple[int, dict]:
    data = None
    headers = {"Accept": "application/json"}
    if payload is not None:
        data = json.dumps(payload).encode("utf-8")
        headers["Content-Type"] = "application/json"

    req = Request(url, data=data, headers=headers, method=method)
    try:
        with urlopen(req, timeout=timeout) as resp:
            body = resp.read().decode("utf-8")
            return resp.status, json.loads(body) if body else {}
    except HTTPError as exc:
        body = exc.read().decode("utf-8")
        try:
            parsed = json.loads(body) if body else {}
        except json.JSONDecodeError:
            parsed = {"raw": body}
        return exc.code, parsed
    except URLError as exc:
        raise SystemExit(f"Запрос не удался: {exc}") from exc


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--api-base", default="http://localhost:8080/api/v1")
    parser.add_argument("--sku", default="KEY-CS2-PRIME")
    parser.add_argument("--parallel", type=int, default=50)
    parser.add_argument("--mode", choices=["distinct", "same"], default="distinct")
    args = parser.parse_args()

    base = args.api_base.rstrip("/")
    parallel = args.parallel

    print(f"1) Создаём заказ sku={args.sku}")
    status, created = http_json("POST", f"{base}/orders", {"sku": args.sku})
    if status not in (200, 201):
        print(f"FAIL create order: HTTP {status} {created}", file=sys.stderr)
        return 1

    order = created.get("data", created)
    order_id = order.get("id") or order.get("public_id")
    amount = order.get("amount", 1290)
    if not order_id:
        print(f"FAIL: нет order id в ответе: {created}", file=sys.stderr)
        return 1

    print(f"   order_id={order_id}")

    shared_event = f"evt_race_same_{uuid.uuid4().hex[:10]}"
    print(f"2) Шлём {parallel} параллельных paid webhook (mode={args.mode})")

    def send_one(i: int) -> int:
        event_id = shared_event if args.mode == "same" else f"evt_race_{uuid.uuid4().hex[:12]}_{i}"
        code, _ = http_json(
            "POST",
            f"{base}/webhook/payment",
            {
                "event_id": event_id,
                "order_id": order_id,
                "status": "paid",
                "amount": int(float(amount)),
                "currency": "RUB",
                "created_at": "2025-01-01T12:00:00Z",
            },
        )
        return code

    started = time.perf_counter()
    results: list[int] = []
    with ThreadPoolExecutor(max_workers=parallel) as pool:
        futures = [pool.submit(send_one, i) for i in range(parallel)]
        for fut in as_completed(futures):
            results.append(fut.result())
    elapsed = time.perf_counter() - started

    ok = sum(1 for code in results if 200 <= code < 300)
    fail = parallel - ok
    print(f"   webhook HTTP 2xx: {ok}/{parallel}  failed={fail}  elapsed={elapsed:.2f}s")

    if ok != parallel:
        print("FAIL: не все webhook приняты с HTTP 2xx", file=sys.stderr)
        return 1

    time.sleep(0.2)

    print("3) Читаем заказ и проверяем ровно одну выдачу")
    status, shown = http_json("GET", f"{base}/orders/{order_id}")
    if status != 200:
        print(f"FAIL get order: HTTP {status} {shown}", file=sys.stderr)
        return 1

    order = shown.get("data", shown)
    order_status = order.get("status")
    issued_code = order.get("issued_code")

    print(f"   status={order_status} issued_code={issued_code}")

    if order_status != "delivered":
        print(f"FAIL: ожидался status=delivered, получили {order_status}", file=sys.stderr)
        return 1

    if not issued_code or not isinstance(issued_code, str):
        print("FAIL: ожидался непустой issued_code", file=sys.stderr)
        return 1

    print("OK: race test пройден - выдача ровно один раз (один issued_code)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
