import express from "express";
import { spawn } from "child_procwess";

const app = express();
app.use(express.json());

app.post("/", (req, res) => {
  const { command } = req.body ?? {};

  const child = spawn(command, { shell: true });

  let stdout = "";
  let stderr = "";

  child.on("close", (code) => {
    res.json({ command, returncode: code, stdout, stderr });
  });
});

const PORT = process.env.PORT || 5000;
app.listen(PORT, "0.0.0.0", () => console.log(`listening on xd ${PORT}`));
