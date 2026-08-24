package main

import (
	"context"
	"encoding/json"
	"flag"
	"fmt"
	"net"
	"os"
	"os/signal"
	"syscall"

	sqle "github.com/dolthub/go-mysql-server"
	"github.com/dolthub/go-mysql-server/memory"
	"github.com/dolthub/go-mysql-server/server"
	"github.com/dolthub/go-mysql-server/sql"
	"github.com/dolthub/go-mysql-server/sql/types"
)

const engineVersion = "mdi-native-gms-v0.20.0"

type readiness struct {
	Status   string `json:"status"`
	Host     string `json:"host"`
	Port     int    `json:"port"`
	Database string `json:"database"`
	Version  string `json:"version"`
	PID      int    `json:"pid"`
}

func main() {
	listen := flag.String("listen", "127.0.0.1:0", "private TCP endpoint")
	database := flag.String("database", "mdi_native", "initial database")
	flag.Parse()

	listener, err := net.Listen("tcp", *listen)
	if err != nil {
		fatal(err)
	}
	provider, err := probeProvider(*database)
	if err != nil {
		_ = listener.Close()
		fatal(err)
	}
	engine := sqle.NewDefault(provider)
	config := server.Config{
		Protocol:          "tcp",
		Address:           listener.Addr().String(),
		Listener:          listener,
		Version:           engineVersion,
		MaxLoggedQueryLen: -1,
	}
	mysqlServer, err := server.NewServer(config, engine, sql.NewContext, memory.NewSessionBuilder(provider), nil)
	if err != nil {
		_ = listener.Close()
		fatal(err)
	}

	address := listener.Addr().(*net.TCPAddr)
	if err := json.NewEncoder(os.Stdout).Encode(readiness{
		Status:   "ready",
		Host:     address.IP.String(),
		Port:     address.Port,
		Database: *database,
		Version:  engineVersion,
		PID:      os.Getpid(),
	}); err != nil {
		_ = mysqlServer.Close()
		fatal(err)
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	go func() {
		<-ctx.Done()
		_ = mysqlServer.Close()
	}()
	if err := mysqlServer.Start(); err != nil {
		fatal(err)
	}
}

func probeProvider(database string) (*memory.DbProvider, error) {
	db := memory.NewDatabase(database)
	db.BaseDatabase.EnablePrimaryKeyIndexes()
	provider := memory.NewDBProvider(db)
	table := memory.NewTable(db, "compat_probe", sql.NewPrimaryKeySchema(sql.Schema{
		{Name: "id", Type: types.Int64, Nullable: false, Source: "compat_probe", PrimaryKey: true},
		{Name: "label", Type: types.Text, Nullable: false, Source: "compat_probe"},
	}), db.GetForeignKeyCollection())
	db.AddTable("compat_probe", table)

	session := memory.NewSession(sql.NewBaseSession(), provider)
	ctx := sql.NewContext(context.Background(), sql.WithSession(session))
	for _, row := range []sql.Row{
		sql.NewRow(int64(1), "native-one"),
		sql.NewRow(int64(2), "native-two"),
	} {
		if err := table.Insert(ctx, row); err != nil {
			return nil, err
		}
	}
	return provider, nil
}

func fatal(err error) {
	_, _ = fmt.Fprintf(os.Stderr, "mdi-native-engine: %v\n", err)
	os.Exit(1)
}
